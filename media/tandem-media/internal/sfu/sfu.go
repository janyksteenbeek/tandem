// Package sfu runs the LiveKit media server inside this process. It is the upstream server,
// unmodified: the same configuration file, the same command-line flags and environment
// variables, started through the same exported constructors that cmd/server uses.
package sfu

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"os"
	"sort"
	"strconv"
	"time"

	"github.com/urfave/cli/v3"

	"github.com/livekit/livekit-server/pkg/config"
	"github.com/livekit/livekit-server/pkg/routing"
	"github.com/livekit/livekit-server/pkg/service"
	"github.com/livekit/livekit-server/pkg/telemetry/prometheus"
	"github.com/livekit/livekit-server/version"
	"github.com/livekit/protocol/logger"
	"github.com/livekit/protocol/tracer/jaeger"
)

// Version is the version of the embedded LiveKit server.
const Version = version.Version

// startTimeout is how long the server may take to begin accepting connections.
const startTimeout = 30 * time.Second

// baseFlags mirrors cmd/server of the upstream project, so that everything written for
// livekit-server (--config, LIVEKIT_CONFIG, LIVEKIT_KEYS, NODE_IP, --dev, ...) works here.
var baseFlags = []cli.Flag{
	&cli.StringSliceFlag{
		Name:  "bind",
		Usage: "IP address to listen on, use flag multiple times to specify multiple addresses",
	},
	&cli.StringFlag{
		Name:  "config",
		Usage: "path to LiveKit config file",
	},
	&cli.StringFlag{
		Name:    "config-body",
		Usage:   "LiveKit config in YAML, typically passed in as an environment var in a container",
		Sources: cli.EnvVars("LIVEKIT_CONFIG"),
	},
	&cli.StringFlag{
		Name:  "key-file",
		Usage: "path to file that contains API keys/secrets",
	},
	&cli.StringFlag{
		Name:    "keys",
		Usage:   "api keys (key: secret\\n)",
		Sources: cli.EnvVars("LIVEKIT_KEYS"),
	},
	&cli.StringFlag{
		Name:    "region",
		Usage:   "region of the current node. Used by regionaware node selector",
		Sources: cli.EnvVars("LIVEKIT_REGION"),
	},
	&cli.StringFlag{
		Name:    "node-ip",
		Usage:   "IP address of the current node, used to advertise to clients. Automatically determined by default",
		Sources: cli.EnvVars("NODE_IP"),
	},
	&cli.StringFlag{
		Name:    "udp-port",
		Usage:   "UDP port(s) to use for WebRTC traffic",
		Sources: cli.EnvVars("UDP_PORT"),
	},
	&cli.StringFlag{
		Name:    "redis-host",
		Usage:   "host (incl. port) to redis server",
		Sources: cli.EnvVars("REDIS_HOST"),
	},
	&cli.StringFlag{
		Name:    "redis-password",
		Usage:   "password to redis",
		Sources: cli.EnvVars("REDIS_PASSWORD"),
	},
	&cli.StringFlag{
		Name:    "turn-cert",
		Usage:   "tls cert file for TURN server",
		Sources: cli.EnvVars("LIVEKIT_TURN_CERT"),
	},
	&cli.StringFlag{
		Name:    "turn-key",
		Usage:   "tls key file for TURN server",
		Sources: cli.EnvVars("LIVEKIT_TURN_KEY"),
	},
	&cli.BoolFlag{
		Name:  "dev",
		Usage: "sets log-level to debug, console formatter, and /debug/pprof. insecure for production",
	},
	&cli.BoolFlag{
		Name:   "disable-strict-config",
		Usage:  "disables strict config parsing",
		Hidden: true,
	},
}

// Flags returns the flags of livekit-server, including the generated one-per-config-key flags.
func Flags() []cli.Flag {
	generated, err := config.GenerateCLIFlags(baseFlags, true)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
	}

	return append(append([]cli.Flag{}, baseFlags...), generated...)
}

// LoadConfig builds the server configuration from --config / LIVEKIT_CONFIG and the flags,
// exactly as livekit-server does.
func LoadConfig(c *cli.Command) (*config.Config, error) {
	body := c.String("config-body")

	if file := c.String("config"); body == "" && file != "" {
		content, err := os.ReadFile(file)
		if err != nil {
			return nil, err
		}

		body = string(content)
	}

	conf, err := config.NewConfig(body, !c.Bool("disable-strict-config"), c, baseFlags)
	if err != nil {
		return nil, err
	}

	config.InitLoggerFromConfig(&conf.Logging)

	if conf.Development {
		logger.Infow("starting in development mode")

		if len(conf.Keys) == 0 {
			logger.Infow("no keys provided, using placeholder keys", "API Key", "devkey", "API Secret", "secret")
			conf.Keys = map[string]string{"devkey": "secret"}

			matchRTCIP := false

			// With the shared development keys the server binds to localhost by default.
			if conf.BindAddresses == nil {
				conf.BindAddresses = []string{"127.0.0.1", "::1"}
			} else {
				for _, address := range conf.BindAddresses {
					if ip := net.ParseIP(address); ip != nil && !ip.IsLoopback() && !ip.IsUnspecified() {
						matchRTCIP = true
					}
				}
			}

			if matchRTCIP {
				for _, address := range conf.BindAddresses {
					conf.RTC.IPs.Includes = append(conf.RTC.IPs.Includes, address+"/24")
				}
			}
		}
	}

	return conf, nil
}

// Server is a running media server.
type Server struct {
	conf   *config.Config
	server *service.LivekitServer
	exited chan error
	client *http.Client
}

// Start launches the media server and returns once it accepts connections.
func Start(ctx context.Context, conf *config.Config) (*Server, error) {
	if url := conf.Trace.JaegerURL; url != "" {
		jaeger.Configure(ctx, url, "livekit")
	}

	if err := conf.ValidateKeys(); err != nil {
		return nil, err
	}

	if err := conf.LoadTURNSecrets(); err != nil {
		return nil, err
	}

	node, err := routing.NewLocalNode(conf)
	if err != nil {
		return nil, err
	}

	if err := prometheus.Init(string(node.NodeID()), node.NodeType()); err != nil {
		return nil, err
	}

	server, err := service.InitializeServer(conf, node)
	if err != nil {
		return nil, err
	}

	s := &Server{
		conf:   conf,
		server: server,
		exited: make(chan error, 1),
		client: &http.Client{Timeout: 2 * time.Second},
	}

	go func() { s.exited <- server.Start() }()

	deadline := time.NewTimer(startTimeout)
	defer deadline.Stop()

	poll := time.NewTicker(50 * time.Millisecond)
	defer poll.Stop()

	for {
		select {
		case err := <-s.exited:
			if err == nil {
				err = errors.New("the media server stopped while starting")
			}

			s.exited <- err

			return nil, err
		case <-deadline.C:
			go server.Stop(true)

			return nil, errors.New("the media server did not start accepting connections in time")
		case <-poll.C:
			if server.IsRunning() && s.reachable() {
				return s, nil
			}
		}
	}
}

// Exited delivers the result of the server's run loop when it ends.
func (s *Server) Exited() <-chan error { return s.exited }

// Stop shuts the server down, disconnecting whoever is still in a room.
func (s *Server) Stop() { s.server.Stop(true) }

// Healthy reports whether the server is running and answering its own health endpoint.
func (s *Server) Healthy() error {
	if !s.server.IsRunning() {
		return errors.New("the media server is not running")
	}

	response, err := s.client.Get("http://" + s.address() + "/")
	if err != nil {
		return fmt.Errorf("the media server does not answer: %w", err)
	}
	defer response.Body.Close()

	if response.StatusCode != http.StatusOK {
		return fmt.Errorf("the media server reports status %d", response.StatusCode)
	}

	return nil
}

// URL is the WebSocket address the in-process recorder uses to join rooms.
func (s *Server) URL() string { return "ws://" + s.address() }

// Credentials returns an API key pair from the server's configuration: the first one in
// alphabetical order, so that the choice is stable when several are configured.
func (s *Server) Credentials() (key, secret string, ok bool) {
	keys := make([]string, 0, len(s.conf.Keys))
	for name := range s.conf.Keys {
		keys = append(keys, name)
	}

	if len(keys) == 0 {
		return "", "", false
	}

	sort.Strings(keys)

	return keys[0], s.conf.Keys[keys[0]], true
}

func (s *Server) reachable() bool {
	response, err := s.client.Get("http://" + s.address() + "/")
	if err != nil {
		return false
	}

	_ = response.Body.Close()

	return true
}

// address is where this process reaches its own media server: loopback, unless the server
// was bound to specific addresses that do not include it.
func (s *Server) address() string {
	host := "127.0.0.1"

	if bound := s.conf.BindAddresses; len(bound) > 0 {
		host = ""

		for _, address := range bound {
			ip := net.ParseIP(address)

			switch {
			case ip == nil:
				continue
			case ip.IsUnspecified() && ip.To4() != nil, ip.IsLoopback() && ip.To4() != nil:
				host = "127.0.0.1"
			case host == "" && (ip.IsUnspecified() || ip.IsLoopback()):
				host = "::1"
			}

			if host == "127.0.0.1" {
				break
			}
		}

		if host == "" {
			host = bound[0]
		}
	}

	return net.JoinHostPort(host, strconv.Itoa(int(s.conf.Port)))
}
