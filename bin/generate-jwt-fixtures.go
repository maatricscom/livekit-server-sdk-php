//go:build ignore

// Emits reference JWTs from livekit/protocol's own auth package, so the PHP
// implementation can be asserted against the code the server actually runs.
//
//   go mod init fixtures && go get github.com/livekit/protocol@v1.52.0
//   go run bin/generate-jwt-fixtures.go > tests/Fixtures/go-tokens.json
package main

import (
	"encoding/json"
	"fmt"
	"time"

	"github.com/livekit/protocol/auth"
)

func main() {
	const key, secret = "devkey", "secret-that-is-long-enough-for-hs256"

	canPublish := false

	cases := map[string]*auth.AccessToken{
		"join_room": auth.NewAccessToken(key, secret).
			SetIdentity("alice").
			SetName("Alice").
			SetValidFor(6 * time.Hour).
			SetVideoGrant(&auth.VideoGrant{RoomJoin: true, Room: "my-room"}),

		"publish_denied": auth.NewAccessToken(key, secret).
			SetIdentity("bob").
			SetValidFor(time.Hour).
			SetVideoGrant(&auth.VideoGrant{RoomJoin: true, Room: "my-room", CanPublish: &canPublish}),

		"room_admin": auth.NewAccessToken(key, secret).
			SetValidFor(10 * time.Minute).
			SetVideoGrant(&auth.VideoGrant{RoomAdmin: true, Room: "my-room"}),

		"sip_call": auth.NewAccessToken(key, secret).
			SetValidFor(10 * time.Minute).
			SetVideoGrant(&auth.VideoGrant{}).
			SetSIPGrant(&auth.SIPGrant{Call: true}),
	}

	out := map[string]string{}
	for name, at := range cases {
		jwt, err := at.ToJWT()
		if err != nil {
			panic(err)
		}
		out[name] = jwt
	}

	encoded, _ := json.MarshalIndent(out, "", "  ")
	fmt.Println(string(encoded))
}
