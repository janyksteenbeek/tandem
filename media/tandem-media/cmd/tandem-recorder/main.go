// Command tandem-recorder is the recording half of Tandem's media stack on its own: a small
// service that joins the rooms of a LiveKit server as a hidden participant and writes every
// track to its own WebM file without decoding or re-encoding anything.
//
// Most installations run tandem-media instead, which is this recorder and the media server
// in one process. Use this command to record from a separate host. Because the calls do not
// depend on it, a restart leaves recordings open and the next process continues them.
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/app"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/config"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/recorder"
)

// shutdownGrace is how long a SIGTERM may take to finalise open files and flush webhooks.
const shutdownGrace = 25 * time.Second

func main() {
	healthcheck := flag.Bool("healthcheck", false, "probe the running recorder's /healthz and exit 0 or 1")
	version := flag.Bool("version", false, "print the version and exit")
	inspect := flag.String("inspect", "", "print the structure of a recorded .webm file as JSON and exit")
	repair := flag.String("repair", "", "finish a .webm file left unfinished by a crash (in place), print the result and exit")
	flag.Parse()

	switch {
	case *version:
		fmt.Println(recorder.Version)

		return
	case *inspect != "" || *repair != "":
		os.Exit(app.FileTool(*inspect, *repair))
	case *healthcheck:
		os.Exit(app.Probe())
	}

	cfg, err := config.FromEnv(config.Defaults{})
	log := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: cfg.LogLevel}))

	if err != nil {
		log.Error("invalid configuration", "error", err.Error())
		os.Exit(2)
	}

	service, err := app.Start(app.Options{Config: cfg, Log: log})
	if err != nil {
		log.Error("recorder could not start", "error", err.Error())
		os.Exit(2)
	}

	signals := make(chan os.Signal, 1)
	signal.Notify(signals, syscall.SIGTERM, syscall.SIGINT)

	exit := 0

	select {
	case received := <-signals:
		log.Info("shutting down", "signal", received.String())
	case err := <-service.Failed():
		log.Error("http server failed", "error", err.Error())
		exit = 1
	}

	ctx, cancel := context.WithTimeout(context.Background(), shutdownGrace)
	defer cancel()

	service.Shutdown(ctx)

	log.Info("stopped")
	os.Exit(exit)
}
