//go:build ignore

// Emits a reference WebhookEvent body, marshaled exactly as LiveKit's own
// webhook/url_notifier.go marshals it before signing (protojson via
// livekit/protocol's utils/protojson.Marshal, i.e. protojson.MarshalOptions{
// AllowPartial: true}), so the PHP implementation's byte-sensitive tests can
// be checked against real Go output rather than a hand-authored
// approximation.
//
//   go mod init fixtures && go get github.com/livekit/protocol@v1.52.0
//   go run bin/generate-webhook-fixture.go > tests/Fixtures/webhook-event.json
package main

import (
	"fmt"

	"github.com/livekit/protocol/livekit"
	"github.com/livekit/protocol/utils/protojson"
)

func main() {
	event := &livekit.WebhookEvent{
		Event:     "room_started",
		Id:        "EV_abc123",
		CreatedAt: 1789891388,
		Room: &livekit.Room{
			Sid:  "RM_xyz",
			Name: "my-room",
		},
	}

	encoded, err := protojson.Marshal(event)
	if err != nil {
		panic(err)
	}
	fmt.Print(string(encoded))
}
