// Package signature implements the timestamped HMAC scheme shared with Tandem:
//
//	X-Tandem-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<rawBody>")>
//
// The PHP counterpart is App\Services\Mail\Providers\Forwarding\WebhookSignature; both sides
// are pinned to the same known vector in their test suites.
package signature

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"strconv"
	"strings"
)

// Header is the HTTP header that carries the signature.
const Header = "X-Tandem-Signature"

// ToleranceSeconds is how far a timestamp may be from "now" before it is refused.
const ToleranceSeconds = 300

// Digest returns the hex HMAC-SHA256 of "<timestamp>.<body>".
func Digest(secret string, timestamp int64, body []byte) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(strconv.FormatInt(timestamp, 10)))
	mac.Write([]byte{'.'})
	mac.Write(body)

	return hex.EncodeToString(mac.Sum(nil))
}

// Sign returns the header value for a body signed at the given unix time.
func Sign(secret string, body []byte, timestamp int64) string {
	return "t=" + strconv.FormatInt(timestamp, 10) + ",v1=" + Digest(secret, timestamp, body)
}

// Verify checks a header value against a body.
func Verify(secret string, body []byte, header string, now int64) bool {
	if secret == "" || header == "" {
		return false
	}

	var (
		timestamp int64
		digest    string
		haveTime  bool
	)

	for _, pair := range strings.Split(header, ",") {
		key, value, ok := strings.Cut(strings.TrimSpace(pair), "=")
		if !ok {
			continue
		}

		switch key {
		case "t":
			parsed, err := strconv.ParseInt(value, 10, 64)
			if err != nil || parsed < 0 {
				return false
			}
			timestamp, haveTime = parsed, true
		case "v1":
			digest = value
		}
	}

	if !haveTime || len(digest) != sha256.Size*2 {
		return false
	}

	if delta := now - timestamp; delta > ToleranceSeconds || delta < -ToleranceSeconds {
		return false
	}

	return hmac.Equal([]byte(Digest(secret, timestamp, body)), []byte(digest))
}
