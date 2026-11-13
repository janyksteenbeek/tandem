package app

import (
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"time"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/config"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/webm"
)

// Probe implements -healthcheck for container images that ship no shell or curl: it asks
// the running process for /healthz and returns the exit code.
func Probe() int {
	client := &http.Client{Timeout: 3 * time.Second}

	response, err := client.Get(config.HealthURL())
	if err != nil {
		fmt.Fprintln(os.Stderr, "unhealthy:", err)

		return 1
	}
	defer response.Body.Close()

	if response.StatusCode != http.StatusOK {
		fmt.Fprintln(os.Stderr, "unhealthy: status", response.StatusCode)

		return 1
	}

	return 0
}

// FileTool implements -inspect and -repair, for looking at or salvaging a file by hand.
func FileTool(inspect, repair string) int {
	var (
		info webm.Info
		err  error
	)

	result := map[string]any{}

	if repair != "" {
		var repaired bool

		info, repaired, err = webm.Repair(repair)
		result["file"], result["repaired"] = repair, repaired
	} else {
		info, err = webm.Inspect(inspect)
		result["file"] = inspect
	}

	if err != nil {
		fmt.Fprintln(os.Stderr, err)

		return 1
	}

	result["finalised"] = info.Finalised
	result["duration_ms"] = info.DurationMs
	result["last_timestamp_ms"] = info.LastTimestampMs
	result["frames"] = info.Frames
	result["clusters"] = info.Clusters
	result["cue_points"] = info.CuePoints
	result["size_bytes"] = info.Size

	_ = json.NewEncoder(os.Stdout).Encode(result)

	return 0
}
