---
name: livekit-live-testing
description: Use when writing or running anything that talks to a real LiveKit deployment — the integration suite, a one-off probe, or a debugging script. Covers which RPCs may be run for real, how to reach the ones that cannot without placing a call or incurring a charge, and what to verify afterwards.
---

# Testing against a real LiveKit deployment

The failure modes here are not red tests. They are a telephone ringing at a
stranger's number, a recording billed to someone's project, and objects left
behind in it. None of those can be undone by fixing the code afterwards, so the
care goes in before the call, not after.

`tests/Integration/` already encodes all of this. Read the class docblocks in
`SipIntegrationTest`, `ConnectorIntegrationTest` and `ServiceMethodSweepIntegrationTest`
before adding anything — they record *why* each call is shaped the way it is.

## Before you run anything

Credentials come from `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`, passed
inline on the command. Never write them to a file, a fixture, or a commit.

```bash
vendor/bin/phpunit --testsuite integration            # the whole suite
vendor/bin/phpunit --testsuite integration --filter X  # one test
```

`--testsuite` is not optional. The default suite excludes `tests/Integration`, so a
bare `--filter` matches nothing and reports success.

## Three categories of RPC

Decide which one you are in before writing the call.

**Safe to run for real.** Rooms, ingress on the push input types, agent dispatch,
SIP trunks and dispatch rules, and `connectTwilioCall()`. These create
configuration or empty objects: nothing dials, nothing records, nothing is billed
until media or a call actually arrives. Drive the full lifecycle and assert the
results.

`connectTwilioCall()` is the one with a side effect worth knowing about: it
provisions a transient room of its own, named `wactr_...`, which `listRooms()`
does not report for at least twenty seconds afterwards. It is empty, carries its
own timeout and closes itself, so leave it — see the note below before treating
it as a leak.

**Must be aimed so the server refuses.** Every egress start, `createSipParticipant()`,
`transferSipParticipant()`, and the WhatsApp connector calls. Aim them at an object
that does not exist — a room, a trunk id, a participant — so the server fails the
lookup before it acts. The expected Twirp code is then the assertion, and a good
one: a `not_found` for something genuinely absent proves the route exists, the
message encoded into something the server decoded, and the grant was accepted. A
wrong route, a mis-encoded field or a missing grant fails as `bad_route`,
`malformed` or `permission_denied` instead.

**Never sent at all.** A pull-type ingress (`URL_INPUT`) with a working URL, because
the server starts fetching the moment it exists. Assert the server validates an
incomplete one instead.

## Making a refusal reliable rather than hopeful

Prefer a request that dies at LiveKit's *own* validation over one that depends on a
third party refusing it. For the WhatsApp connector, LiveKit checks the SDP type,
then the Cloud API version, and only then forwards anything to Meta — so a
deliberately unsupported API version cannot reach Meta at all. A plausible request
with a wrong API key would reach Meta and rely on Meta saying no, which is one
upstream change away from placing a real call.

Any phone number that appears anywhere must be inside **+1 555 0100–0199**, reserved
so it can never reach a subscriber. `SipIntegrationTest::FICTIONAL_NUMBER` holds it.

Use `noFailoverClient()` for any call whose answer you already know. Failover
replays retryable failures across regions; measured against a live project, the
same failing call took 7.8 seconds with failover off and 177 with it on.

## Cleaning up

Everything created goes through `IntegrationTestCase::cleanUpAfter()`, which deletes
on the way out even when an assertion fails and reports the original failure rather
than the cleanup failure that follows it.

One documented exception exists, in `ConnectorIntegrationTest`. Do not add another
without writing down why: cleanup code that looks thorough and does nothing is worse
than an honest note saying it cannot be done.

After any real run, confirm the project is empty. Do not assume the suite did it.
A `wactr_` room in the count is the Twilio artefact above closing itself out, not
a leak; anything else is:

```bash
php -r 'require "vendor/autoload.php"; $a = new LiveKit\LiveKitAPI();
printf("rooms:%d ingress:%d egress:%d inbound:%d outbound:%d rules:%d\n",
  count($a->room->listRooms()), count($a->ingress->listIngress()), count($a->egress->listEgress()),
  count($a->sip->listSipInboundTrunk()), count($a->sip->listSipOutboundTrunk()),
  count($a->sip->listSipDispatchRule()));'
```

If a probe ever returns a timeout or an unexpected success on a start call, check for
an orphan immediately — you may hold no id for something the server did create.

## Skips are not failures here

`skipIfUnavailable()` is how this suite reports a feature the deployment does not
have, and a project without SIP or egress is a valid one to run against. This is why
the integration job does **not** use `--fail-on-skipped`, and why the mock-server job
does. Do not make the two consistent; see `ToolingConfigTest`.
