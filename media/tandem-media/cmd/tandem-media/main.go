// Command tandem-media is Tandem's whole media stack in one process: the LiveKit media
// server (SFU, signalling and the built-in TURN server) and the recorder that writes calls
// to disk. The recorder joins rooms over loopback as a hidden participant, exactly as it
// does when it runs on its own, so the two halves stay independent: a recording that fails
// never touches a call.
//
// The media server is configured like livekit-server (--config <file>, or the YAML itself
// in LIVEKIT_CONFIG). The recorder is configured through RECORDER_* and RECORDINGS_DIR and
// is switched off when RECORDER_SECRET is not set.
package main

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"runtime/debug"
	"strings"
	"syscall"
	"time"

	"github.com/urfave/cli/v3"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/api"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/app"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/config"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/recorder"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/sfu"
)

// recorderGrace is how long a shutdown may spend finalising recordings and delivering their
// webhooks before the media server is stopped regardless.
const recorderGrace = 20 * time.Second

func main() {
	if code, handled := tool(os.Args[1:]); handled {
		os.Exit(code)
	}

	command := &cli.Command{
		Name:        "tandem-media",
		Usage:       "LiveKit media server and Tandem recorder in one process",
		Description: "Accepts the flags and environment of livekit-server. Also: -healthcheck, -version, -inspect <file>, -repair <file>.",
		Flags:       sfu.Flags(),
		Action:      run,
		// -version is handled above so that it can report both halves.
		HideVersion: true,
	}

	if err := command.Run(context.Background(), os.Args); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

// tool handles the flags that do something other than run the server. They are accepted
// with one dash or two, as with the standalone recorder.
func tool(args []string) (code int, handled bool) {
	if len(args) == 0 {
		return 0, false
	}

	name := strings.TrimLeft(args[0], "-")
	if !strings.HasPrefix(args[0], "-") {
		return 0, false
	}

	value := ""
	if before, after, found := strings.Cut(name, "="); found {
		name, value = before, after
	} else if len(args) > 1 {
		value = args[1]
	}

	switch name {
	case "version":
		fmt.Printf("tandem-media %s (livekit-server %s)\n", recorder.Version, sfu.Version)

		return 0, true
	case "healthcheck":
		return app.Probe(), true
	case "inspect", "repair":
		if value == "" {
			fmt.Fprintf(os.Stderr, "-%s needs a file\n", name)

			return 2, true
		}

		if name == "repair" {
			return app.FileTool("", value), true
		}

		return app.FileTool(value, ""), true
	}

	return 0, false
}

func run(ctx context.Context, c *cli.Command) error {
	conf, err := sfu.LoadConfig(c)
	if err != nil {
		return err
	}

	server, err := sfu.Start(ctx, conf)
	if err != nil {
		return err
	}

	log := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: recorderLogLevel()}))
	log.Info("media server running", "livekit", sfu.Version, "tandem_media", recorder.Version, "url", server.URL())

	service := startRecorder(log, server)

	signals := make(chan os.Signal, 2)
	signal.Notify(signals, syscall.SIGTERM, syscall.SIGINT, syscall.SIGQUIT)

	var failed <-chan error
	if service != nil {
		failed = service.Failed()
	}

	var result error

wait:
	for {
		select {
		case received := <-signals:
			log.Info("shutting down", "signal", received.String())

			break wait
		case result = <-server.Exited():
			log.Error("the media server stopped", "error", fmt.Sprint(result))

			break wait
		case err := <-failed:
			// Calls do not depend on the recorder's control API; keep serving them.
			log.Error("the recorder API stopped; recording is unavailable until the process restarts", "error", err.Error())

			failed = nil
		}
	}

	// A second signal skips the orderly shutdown.
	go func() {
		<-signals
		log.Warn("second signal received, exiting immediately")
		os.Exit(1)
	}()

	// Recordings first: once the media server stops, the calls are over. Finalise every
	// file and tell Tandem while the process is still healthy.
	if service != nil {
		ctx, cancel := context.WithTimeout(context.Background(), recorderGrace)
		service.Shutdown(ctx)
		cancel()
	}

	if result == nil {
		server.Stop()
		<-server.Exited()
	}

	log.Info("stopped")

	return result
}

// startRecorder starts the recording half. It never returns an error: whatever is wrong
// with the recorder, the media server keeps running and /healthz says so.
func startRecorder(log *slog.Logger, server *sfu.Server) (service *app.Recorder) {
	defer func() {
		if cause := recover(); cause != nil {
			log.Error("recovered from a panic while starting the recorder; recording is disabled",
				"panic", fmt.Sprint(cause), "stack", string(debug.Stack()))

			service = healthOnly(log, server.Healthy, func() error { return fmt.Errorf("the recorder failed to start") })
		}
	}()

	if !config.Enabled() {
		log.Info("RECORDER_SECRET is not set: recording is disabled, the media server runs on its own")

		return healthOnly(log, server.Healthy)
	}

	key, secret, _ := server.Credentials()

	cfg, err := config.FromEnv(config.Defaults{LiveKitURL: server.URL(), LiveKitKey: key, LiveKitSecret: secret})
	if err == nil {
		service, err = app.Start(app.Options{
			Config:           cfg,
			Log:              log,
			FinishOnShutdown: true,
			Checks:           []api.Check{server.Healthy},
		})
	}

	if err != nil {
		log.Error("the recorder could not start; recording is disabled, calls are not affected", "error", err.Error())

		broken := err

		return healthOnly(log, server.Healthy, func() error { return fmt.Errorf("the recorder is not running: %w", broken) })
	}

	return service
}

// healthOnly serves /healthz when there is no recorder to serve it.
func healthOnly(log *slog.Logger, checks ...api.Check) *app.Recorder {
	service, err := app.StartHealthOnly(log, checks...)
	if err != nil {
		log.Error("the health endpoint could not start", "addr", config.Listen(), "error", err.Error())

		return nil
	}

	return service
}

func recorderLogLevel() slog.Level {
	level := slog.LevelInfo
	if raw := os.Getenv("LOG_LEVEL"); raw != "" {
		_ = level.UnmarshalText([]byte(strings.ToUpper(raw)))
	}

	return level
}
