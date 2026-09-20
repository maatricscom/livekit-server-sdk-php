---
name: livekit-add-rpc
description: Use when adding a method to a service client, adding a whole service client, or when RpcCoverageTest fails. Covers the grant, which is the part that is invisible until production, and the chain of guard tests a new method has to satisfy.
---

# Adding an RPC to a service client

The code is the easy half. The grant is the half that is wrong silently.

## The grant is per RPC, not per client

`ServiceBase::authHeader(VideoGrant, ?SIPGrant)` is called once per method, because
LiveKit's permission table is per RPC and parts of it do not follow from the method
name. `deleteRoom` needs `roomCreate`, not `roomAdmin`. The two SIP call methods need
`sip.call`, not `sip.admin`. `roomAdmin` is room-scoped server-side, so the room name
goes into the grant and not only into the request body.

The mapping lives in §6 of the design spec, `docs/design.md`. Read it
there rather than inferring from a neighbouring method.

A grant that is too narrow fails only against a deployment that enforces it, which
means it passes every unit test and fails at a user. `RpcSweepTest` is what catches
it, because the mock server enforces the same table as the real one — verified by
mutation: weakening `deleteRoom`'s grant to `roomList` fails that sweep.

## The chain

Adding the method is step one of several, and the guards will tell you which you
missed. They are not obstacles; each one exists because something was once forgotten.

1. **The method**, on the client. Options go in `LiveKit\Options\*` as `final readonly`
   DTOs with named arguments; return the generated `LiveKit\Proto\*` message, except
   for a list RPC, which is unwrapped to a plain array.
2. **The interface** in `LiveKit\Contracts`. Each one declares every method its client
   has, and `ReadmeExamplesTest` checks the table in the README that documents this.
3. **The sweep entry** in `RpcSweepTest::rpcs()`. `RpcCoverageTest` fails without it,
   in both directions — it also fails if an entry names a method that no longer
   exists. It derives the expected list by reflection over `LiveKitAPI`, so a whole new
   client is covered the moment it is wired into the facade.
4. **A unit test** asserting the Twirp path, the grant in the minted token, and the
   option-to-proto mapping.
5. **The README**, if the method is something a user would reach for. Every PHP block
   there is parsed by `ReadmeCodeBlocksTest`, which checks that every class, named
   argument, constant and resolvable method call exists — so a snippet cannot
   silently describe an API that does not.
6. **`CHANGELOG.md`**, once there is a release to add it to. A new method on a shipped
   client is a notable change; nothing enforces this, which is exactly why it is on the
   list. Keep it to what a consumer can act on — the entry describes the method, not the
   work of adding it.

## Partial updates

If the RPC has a `*Fields()` sibling, the distinction is load-bearing and the names do
not carry it: the plain form replaces the object wholesale and **clears what you
omit**, the `*Fields()` form changes only what you pass. Choosing wrong silently drops
authentication credentials or a phone number. Any test for a partial update must
assert that the fields it did *not* send survived — see `SipIntegrationTest`.

## Tri-state permissions

If the method touches `VideoGrant`'s `?bool` fields, `null` omits the claim and lets
the server apply its default, which **allows** publish and subscribe. `false` is an
explicit denial that has to reach the wire. Never filter falsey values out of a grant.

## Before you are done

```bash
composer test && composer analyse && composer lint
```

Then run it against something real, in this order — each proves what the one before
it cannot:

```bash
vendor/bin/phpunit --testsuite mock-server --fail-on-skipped   # the grant and both wire formats
vendor/bin/phpunit --testsuite integration                     # a real deployment
```

The mock-server suite is where the grant is proved. See the `livekit-live-testing`
skill before pointing anything at a real deployment.
