package signature

import "testing"

// The same vector is pinned in the Laravel test suite.
const (
	vectorSecret    = "test-secret"
	vectorTimestamp = 1700000000
	vectorBody      = `{"hello":"world"}`
	vectorDigest    = "f85455a1b55ea86f7a15f5f9923d0abc4b888da84ec485f2ff358f427776beca"
)

func TestSharedVector(t *testing.T) {
	if got := Digest(vectorSecret, vectorTimestamp, []byte(vectorBody)); got != vectorDigest {
		t.Fatalf("digest = %s, want %s", got, vectorDigest)
	}

	header := Sign(vectorSecret, []byte(vectorBody), vectorTimestamp)
	if want := "t=1700000000,v1=" + vectorDigest; header != want {
		t.Fatalf("header = %s, want %s", header, want)
	}
}

func TestVerify(t *testing.T) {
	body := []byte(vectorBody)
	header := Sign(vectorSecret, body, vectorTimestamp)

	cases := []struct {
		name   string
		secret string
		body   []byte
		header string
		now    int64
		want   bool
	}{
		{"valid", vectorSecret, body, header, vectorTimestamp + 10, true},
		{"valid at tolerance edge", vectorSecret, body, header, vectorTimestamp + ToleranceSeconds, true},
		{"expired", vectorSecret, body, header, vectorTimestamp + ToleranceSeconds + 1, false},
		{"from the future", vectorSecret, body, header, vectorTimestamp - ToleranceSeconds - 1, false},
		{"wrong secret", "other", body, header, vectorTimestamp, false},
		{"tampered body", vectorSecret, []byte(`{"hello":"mars"}`), header, vectorTimestamp, false},
		{"empty secret", "", body, header, vectorTimestamp, false},
		{"missing header", vectorSecret, body, "", vectorTimestamp, false},
		{"garbage header", vectorSecret, body, "nonsense", vectorTimestamp, false},
		{"short digest", vectorSecret, body, "t=1700000000,v1=abc", vectorTimestamp, false},
	}

	for _, c := range cases {
		if got := Verify(c.secret, c.body, c.header, c.now); got != c.want {
			t.Errorf("%s: Verify = %v, want %v", c.name, got, c.want)
		}
	}
}
