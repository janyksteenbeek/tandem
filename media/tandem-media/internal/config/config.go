// Package config reads the recorder's settings from the environment.
package config

import (
	"errors"
	"fmt"
	"log/slog"
	"os"
	"strconv"
	"strings"
	"time"
)

// Config is everything the recorder needs to run.
type Config struct {
	Listen        string
	Secret        string
	LiveKitURL    string
	LiveKitKey    string
	LiveKitSecret string
	RecordingsDir string
	WebhookURL    string
	MinFreeBytes  uint64
	MaxDuration   time.Duration
	LogLevel      slog.Level

	// KeyframeInterval is the longest a video file may go without a keyframe before the
	// recorder asks the publisher for one. It bounds how far a player has to decode after a seek.
	KeyframeInterval time.Duration
	// EmptyGrace is how long a room may have no participants before the recording is finalised.
	EmptyGrace time.Duration
}

// Defaults are the values used when the environment does not set them. The combined
// tandem-media binary fills them in from the configuration of the media server it embeds.
type Defaults struct {
	LiveKitURL    string
	LiveKitKey    string
	LiveKitSecret string
}

// Listen returns the address the HTTP listener binds to. It never fails, so that
// -healthcheck works with whatever environment the container was given.
func Listen() string {
	return env("RECORDER_LISTEN", ":7890")
}

// Enabled reports whether the environment asks for a recorder at all. The combined binary
// runs the media server alone when no RECORDER_SECRET is set.
func Enabled() bool {
	return os.Getenv("RECORDER_SECRET") != ""
}

// FromEnv loads the configuration and validates what cannot be defaulted.
func FromEnv(defaults Defaults) (Config, error) {
	if defaults.LiveKitURL == "" {
		defaults.LiveKitURL = "ws://127.0.0.1:7880"
	}

	cfg := Config{
		Listen:           Listen(),
		Secret:           os.Getenv("RECORDER_SECRET"),
		LiveKitURL:       env("LIVEKIT_URL", defaults.LiveKitURL),
		LiveKitKey:       env("LIVEKIT_API_KEY", defaults.LiveKitKey),
		LiveKitSecret:    env("LIVEKIT_API_SECRET", defaults.LiveKitSecret),
		RecordingsDir:    os.Getenv("RECORDINGS_DIR"),
		WebhookURL:       os.Getenv("RECORDER_WEBHOOK_URL"),
		MinFreeBytes:     512 << 20,
		MaxDuration:      6 * time.Hour,
		KeyframeInterval: 10 * time.Second,
		EmptyGrace:       30 * time.Second,
		LogLevel:         slog.LevelInfo,
	}

	var problems []string

	for name, value := range map[string]string{
		"RECORDER_SECRET":    cfg.Secret,
		"LIVEKIT_API_KEY":    cfg.LiveKitKey,
		"LIVEKIT_API_SECRET": cfg.LiveKitSecret,
		"RECORDINGS_DIR":     cfg.RecordingsDir,
	} {
		if value == "" {
			problems = append(problems, name+" is required")
		}
	}

	if raw := os.Getenv("RECORDER_MIN_FREE_BYTES"); raw != "" {
		parsed, err := strconv.ParseUint(raw, 10, 64)
		if err != nil {
			problems = append(problems, "RECORDER_MIN_FREE_BYTES must be a number of bytes")
		}
		cfg.MinFreeBytes = parsed
	}

	durations := []struct {
		name   string
		target *time.Duration
	}{
		{"RECORDER_MAX_DURATION", &cfg.MaxDuration},
		{"RECORDER_KEYFRAME_INTERVAL", &cfg.KeyframeInterval},
		{"RECORDER_EMPTY_GRACE", &cfg.EmptyGrace},
	}

	for _, d := range durations {
		raw := os.Getenv(d.name)
		if raw == "" {
			continue
		}

		parsed, err := time.ParseDuration(raw)
		if err != nil || parsed <= 0 {
			problems = append(problems, d.name+" must be a positive duration such as 6h or 90m")

			continue
		}

		*d.target = parsed
	}

	if raw := os.Getenv("LOG_LEVEL"); raw != "" {
		if err := cfg.LogLevel.UnmarshalText([]byte(strings.ToUpper(raw))); err != nil {
			problems = append(problems, "LOG_LEVEL must be debug, info, warn or error")
		}
	}

	if len(problems) > 0 {
		return cfg, errors.New(strings.Join(problems, "; "))
	}

	return cfg, nil
}

// HTTPURL returns the LiveKit address for its HTTP API (ws:// becomes http://).
func (c Config) HTTPURL() string {
	switch {
	case strings.HasPrefix(c.LiveKitURL, "wss://"):
		return "https://" + strings.TrimPrefix(c.LiveKitURL, "wss://")
	case strings.HasPrefix(c.LiveKitURL, "ws://"):
		return "http://" + strings.TrimPrefix(c.LiveKitURL, "ws://")
	default:
		return c.LiveKitURL
	}
}

// HealthURL is the address the -healthcheck flag probes.
func HealthURL() string {
	host := Listen()
	if strings.HasPrefix(host, ":") {
		host = "127.0.0.1" + host
	}

	return fmt.Sprintf("http://%s/healthz", host)
}

func env(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}

	return fallback
}
