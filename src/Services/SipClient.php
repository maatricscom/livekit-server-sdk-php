<?php

declare(strict_types=1);

namespace LiveKit\Services;

use Google\Protobuf\Duration;
use LiveKit\Grants\SIPGrant;
use LiveKit\Grants\VideoGrant;
use LiveKit\Options\CreateSipDispatchRuleOptions;
use LiveKit\Options\CreateSipInboundTrunkOptions;
use LiveKit\Options\CreateSipOutboundTrunkOptions;
use LiveKit\Options\ListSipTrunkOptions;
use LiveKit\Options\SipInboundTrunkUpdateOptions;
use LiveKit\Options\SipOutboundTrunkUpdateOptions;
use LiveKit\Proto\CreateSIPDispatchRuleRequest;
use LiveKit\Proto\CreateSIPInboundTrunkRequest;
use LiveKit\Proto\CreateSIPOutboundTrunkRequest;
use LiveKit\Proto\DeleteSIPTrunkRequest;
use LiveKit\Proto\GetSIPInboundTrunkRequest;
use LiveKit\Proto\GetSIPInboundTrunkResponse;
use LiveKit\Proto\GetSIPOutboundTrunkRequest;
use LiveKit\Proto\GetSIPOutboundTrunkResponse;
use LiveKit\Proto\ListSIPInboundTrunkRequest;
use LiveKit\Proto\ListSIPInboundTrunkResponse;
use LiveKit\Proto\ListSIPOutboundTrunkRequest;
use LiveKit\Proto\ListSIPOutboundTrunkResponse;
use LiveKit\Proto\ListSIPTrunkRequest;
use LiveKit\Proto\ListSIPTrunkResponse;
use LiveKit\Proto\SIPDispatchRule;
use LiveKit\Proto\SIPDispatchRuleInfo;
use LiveKit\Proto\SIPInboundTrunkInfo;
use LiveKit\Proto\SIPInboundTrunkUpdate;
use LiveKit\Proto\SIPOutboundTrunkInfo;
use LiveKit\Proto\SIPOutboundTrunkUpdate;
use LiveKit\Proto\SIPTrunkInfo;
use LiveKit\Proto\UpdateSIPDispatchRuleRequest;
use LiveKit\Proto\UpdateSIPInboundTrunkRequest;
use LiveKit\Proto\UpdateSIPOutboundTrunkRequest;

/**
 * Client for the LiveKit SIP API.
 *
 * Implements the 16 live RPCs of the livekit.SIP service plus the three partial-update
 * convenience wrappers of the Node SDK (updateSipDispatchRuleFields,
 * updateSipInboundTrunkFields, updateSipOutboundTrunkFields).
 *
 * There is no createSipTrunk(): at livekit/protocol v1.52.0 the RPC is commented out in
 * livekit_sip.proto and marked DELETED, so POSTing to /twirp/livekit.SIP/CreateSIPTrunk
 * would 404. The CreateSIPTrunkRequest and SIPTrunkInfo messages still exist - SIPTrunkInfo
 * is the response of the still-live DeleteSIPTrunk - which is why the generated classes are
 * there and the method is not. Use createSipInboundTrunk() or createSipOutboundTrunk().
 *
 * Only createSipParticipant() and transferSipParticipant() can fail with
 * LiveKit\Exceptions\SipCallError: they are the only RPCs whose Twirp error meta carries a
 * SIP status. Everything else fails with a plain LiveKit\Exceptions\TwirpException.
 *
 * Note: this class implements LiveKit\Contracts\SipClientInterface. The `implements`
 * clause is added once all 19 public methods exist (see the closing cycle of Task 13),
 * so that the class never fails to load with a partially-built interface.
 */
final class SipClient extends ServiceBase
{
    /** Twirp service name as it appears in the URL: /twirp/livekit.SIP/<Method>. */
    private const SERVICE = 'SIP';

    /**
     * Creates a SIP inbound trunk.
     *
     * @param list<string> $numbers phone numbers this trunk accepts calls for
     */
    public function createSipInboundTrunk(
        string $name,
        array $numbers,
        ?CreateSipInboundTrunkOptions $opts = null,
    ): SIPInboundTrunkInfo {
        $opts ??= new CreateSipInboundTrunkOptions();

        $trunk = new SIPInboundTrunkInfo();
        $trunk->setName($name);
        $trunk->setNumbers($numbers);

        if ($opts->metadata !== null) {
            $trunk->setMetadata($opts->metadata);
        }
        if ($opts->allowedAddresses !== null) {
            $trunk->setAllowedAddresses($opts->allowedAddresses);
        }
        if ($opts->allowedNumbers !== null) {
            $trunk->setAllowedNumbers($opts->allowedNumbers);
        }
        if ($opts->authUsername !== null) {
            $trunk->setAuthUsername($opts->authUsername);
        }
        if ($opts->authPassword !== null) {
            $trunk->setAuthPassword($opts->authPassword);
        }
        if ($opts->authRealm !== null) {
            $trunk->setAuthRealm($opts->authRealm);
        }
        if ($opts->headers !== null) {
            $trunk->setHeaders($opts->headers);
        }
        if ($opts->headersToAttributes !== null) {
            $trunk->setHeadersToAttributes($opts->headersToAttributes);
        }
        if ($opts->attributesToHeaders !== null) {
            $trunk->setAttributesToHeaders($opts->attributesToHeaders);
        }
        if ($opts->includeHeaders !== null) {
            $trunk->setIncludeHeaders($opts->includeHeaders);
        }
        if ($opts->krispEnabled !== null) {
            $trunk->setKrispEnabled($opts->krispEnabled);
        }
        if ($opts->mediaEncryption !== null) {
            $trunk->setMediaEncryption($opts->mediaEncryption);
        }
        if ($opts->media !== null) {
            $trunk->setMedia($opts->media);
        }
        if ($opts->ringingTimeout !== null) {
            $trunk->setRingingTimeout((new Duration())->setSeconds($opts->ringingTimeout));
        }
        if ($opts->maxCallDuration !== null) {
            $trunk->setMaxCallDuration((new Duration())->setSeconds($opts->maxCallDuration));
        }

        $request = new CreateSIPInboundTrunkRequest();
        $request->setTrunk($trunk);

        $response = $this->rpc(
            self::SERVICE,
            'CreateSIPInboundTrunk',
            $request,
            SIPInboundTrunkInfo::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response;
    }

    /**
     * Creates a SIP outbound trunk.
     *
     * @param string       $address hostname or IP the INVITE is sent to, with no 'sip:' prefix
     * @param list<string> $numbers numbers to place calls from; one is picked at random
     */
    public function createSipOutboundTrunk(
        string $name,
        string $address,
        array $numbers,
        ?CreateSipOutboundTrunkOptions $opts = null,
    ): SIPOutboundTrunkInfo {
        $opts ??= new CreateSipOutboundTrunkOptions();

        $trunk = new SIPOutboundTrunkInfo();
        $trunk->setName($name);
        $trunk->setAddress($address);
        $trunk->setNumbers($numbers);
        $trunk->setTransport($opts->transport);

        if ($opts->metadata !== null) {
            $trunk->setMetadata($opts->metadata);
        }
        if ($opts->destinationCountry !== null) {
            $trunk->setDestinationCountry($opts->destinationCountry);
        }
        if ($opts->authUsername !== null) {
            $trunk->setAuthUsername($opts->authUsername);
        }
        if ($opts->authPassword !== null) {
            $trunk->setAuthPassword($opts->authPassword);
        }
        if ($opts->headers !== null) {
            $trunk->setHeaders($opts->headers);
        }
        if ($opts->headersToAttributes !== null) {
            $trunk->setHeadersToAttributes($opts->headersToAttributes);
        }
        if ($opts->attributesToHeaders !== null) {
            $trunk->setAttributesToHeaders($opts->attributesToHeaders);
        }
        if ($opts->includeHeaders !== null) {
            $trunk->setIncludeHeaders($opts->includeHeaders);
        }
        if ($opts->mediaEncryption !== null) {
            $trunk->setMediaEncryption($opts->mediaEncryption);
        }
        if ($opts->media !== null) {
            $trunk->setMedia($opts->media);
        }
        if ($opts->fromHost !== null) {
            $trunk->setFromHost($opts->fromHost);
        }

        $request = new CreateSIPOutboundTrunkRequest();
        $request->setTrunk($trunk);

        $response = $this->rpc(
            self::SERVICE,
            'CreateSIPOutboundTrunk',
            $request,
            SIPOutboundTrunkInfo::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response;
    }

    /**
     * Replaces a SIP inbound trunk wholesale. Fields left unset on $trunk are cleared.
     * Use updateSipInboundTrunkFields() to change only some fields.
     */
    public function updateSipInboundTrunk(string $sipTrunkId, SIPInboundTrunkInfo $trunk): SIPInboundTrunkInfo
    {
        $request = new UpdateSIPInboundTrunkRequest();
        $request->setSipTrunkId($sipTrunkId);
        $request->setReplace($trunk);

        $response = $this->rpc(
            self::SERVICE,
            'UpdateSIPInboundTrunk',
            $request,
            SIPInboundTrunkInfo::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response;
    }

    /**
     * Updates only the given fields of a SIP inbound trunk, leaving the rest alone.
     * Sends the 'update' arm of the oneof in livekit.UpdateSIPInboundTrunkRequest.
     */
    public function updateSipInboundTrunkFields(
        string $sipTrunkId,
        SipInboundTrunkUpdateOptions $fields,
    ): SIPInboundTrunkInfo {
        $update = new SIPInboundTrunkUpdate();

        if ($fields->numbers !== null) {
            $update->setNumbers($fields->numbers);
        }
        if ($fields->allowedAddresses !== null) {
            $update->setAllowedAddresses($fields->allowedAddresses);
        }
        if ($fields->allowedNumbers !== null) {
            $update->setAllowedNumbers($fields->allowedNumbers);
        }
        if ($fields->authUsername !== null) {
            $update->setAuthUsername($fields->authUsername);
        }
        if ($fields->authPassword !== null) {
            $update->setAuthPassword($fields->authPassword);
        }
        if ($fields->authRealm !== null) {
            $update->setAuthRealm($fields->authRealm);
        }
        if ($fields->name !== null) {
            $update->setName($fields->name);
        }
        if ($fields->metadata !== null) {
            $update->setMetadata($fields->metadata);
        }
        if ($fields->mediaEncryption !== null) {
            $update->setMediaEncryption($fields->mediaEncryption);
        }
        if ($fields->media !== null) {
            $update->setMedia($fields->media);
        }

        $request = new UpdateSIPInboundTrunkRequest();
        $request->setSipTrunkId($sipTrunkId);
        $request->setUpdate($update);

        $response = $this->rpc(
            self::SERVICE,
            'UpdateSIPInboundTrunk',
            $request,
            SIPInboundTrunkInfo::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response;
    }

    /**
     * Replaces a SIP outbound trunk wholesale. Fields left unset on $trunk are cleared.
     * Use updateSipOutboundTrunkFields() to change only some fields.
     */
    public function updateSipOutboundTrunk(string $sipTrunkId, SIPOutboundTrunkInfo $trunk): SIPOutboundTrunkInfo
    {
        $request = new UpdateSIPOutboundTrunkRequest();
        $request->setSipTrunkId($sipTrunkId);
        $request->setReplace($trunk);

        $response = $this->rpc(
            self::SERVICE,
            'UpdateSIPOutboundTrunk',
            $request,
            SIPOutboundTrunkInfo::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response;
    }

    /**
     * Updates only the given fields of a SIP outbound trunk, leaving the rest alone.
     * Sends the 'update' arm of the oneof in livekit.UpdateSIPOutboundTrunkRequest.
     */
    public function updateSipOutboundTrunkFields(
        string $sipTrunkId,
        SipOutboundTrunkUpdateOptions $fields,
    ): SIPOutboundTrunkInfo {
        $update = new SIPOutboundTrunkUpdate();

        if ($fields->address !== null) {
            $update->setAddress($fields->address);
        }
        if ($fields->transport !== null) {
            $update->setTransport($fields->transport);
        }
        if ($fields->destinationCountry !== null) {
            $update->setDestinationCountry($fields->destinationCountry);
        }
        if ($fields->numbers !== null) {
            $update->setNumbers($fields->numbers);
        }
        if ($fields->authUsername !== null) {
            $update->setAuthUsername($fields->authUsername);
        }
        if ($fields->authPassword !== null) {
            $update->setAuthPassword($fields->authPassword);
        }
        if ($fields->name !== null) {
            $update->setName($fields->name);
        }
        if ($fields->metadata !== null) {
            $update->setMetadata($fields->metadata);
        }
        if ($fields->mediaEncryption !== null) {
            $update->setMediaEncryption($fields->mediaEncryption);
        }
        if ($fields->media !== null) {
            $update->setMedia($fields->media);
        }
        if ($fields->fromHost !== null) {
            $update->setFromHost($fields->fromHost);
        }

        $request = new UpdateSIPOutboundTrunkRequest();
        $request->setSipTrunkId($sipTrunkId);
        $request->setUpdate($update);

        $response = $this->rpc(
            self::SERVICE,
            'UpdateSIPOutboundTrunk',
            $request,
            SIPOutboundTrunkInfo::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response;
    }

    /** Fetches one SIP inbound trunk, or null when the server returns no trunk. */
    public function getSipInboundTrunk(string $sipTrunkId): ?SIPInboundTrunkInfo
    {
        $request = new GetSIPInboundTrunkRequest();
        $request->setSipTrunkId($sipTrunkId);

        $response = $this->rpc(
            self::SERVICE,
            'GetSIPInboundTrunk',
            $request,
            GetSIPInboundTrunkResponse::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response->getTrunk();
    }

    /** Fetches one SIP outbound trunk, or null when the server returns no trunk. */
    public function getSipOutboundTrunk(string $sipTrunkId): ?SIPOutboundTrunkInfo
    {
        $request = new GetSIPOutboundTrunkRequest();
        $request->setSipTrunkId($sipTrunkId);

        $response = $this->rpc(
            self::SERVICE,
            'GetSIPOutboundTrunk',
            $request,
            GetSIPOutboundTrunkResponse::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response->getTrunk();
    }

    /**
     * Lists SIP inbound trunks. With no filters, all trunks are listed.
     *
     * @return list<SIPInboundTrunkInfo>
     */
    public function listSipInboundTrunk(?ListSipTrunkOptions $opts = null): array
    {
        $request = new ListSIPInboundTrunkRequest();

        if ($opts !== null) {
            if ($opts->page !== null) {
                $request->setPage($opts->page);
            }
            if ($opts->trunkIds !== null) {
                $request->setTrunkIds($opts->trunkIds);
            }
            if ($opts->numbers !== null) {
                $request->setNumbers($opts->numbers);
            }
        }

        $response = $this->rpc(
            self::SERVICE,
            'ListSIPInboundTrunk',
            $request,
            ListSIPInboundTrunkResponse::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        $items = [];
        foreach ($response->getItems() as $item) {
            // RepeatedField's iterator carries no generic value type, so this
            // yields mixed — unlike the rpc() return, which the analyser infers.
            assert($item instanceof SIPInboundTrunkInfo);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Lists SIP outbound trunks. With no filters, all trunks are listed.
     *
     * @return list<SIPOutboundTrunkInfo>
     */
    public function listSipOutboundTrunk(?ListSipTrunkOptions $opts = null): array
    {
        $request = new ListSIPOutboundTrunkRequest();

        if ($opts !== null) {
            if ($opts->page !== null) {
                $request->setPage($opts->page);
            }
            if ($opts->trunkIds !== null) {
                $request->setTrunkIds($opts->trunkIds);
            }
            if ($opts->numbers !== null) {
                $request->setNumbers($opts->numbers);
            }
        }

        $response = $this->rpc(
            self::SERVICE,
            'ListSIPOutboundTrunk',
            $request,
            ListSIPOutboundTrunkResponse::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        $items = [];
        foreach ($response->getItems() as $item) {
            // RepeatedField's iterator carries no generic value type, so this
            // yields mixed — unlike the rpc() return, which the analyser infers.
            assert($item instanceof SIPOutboundTrunkInfo);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Lists legacy SIP trunks.
     *
     * @deprecated The livekit.SIP.ListSIPTrunk rpc carries `option deprecated = true`.
     *             Use listSipInboundTrunk() or listSipOutboundTrunk().
     *
     * @return list<SIPTrunkInfo>
     */
    public function listSipTrunk(): array
    {
        $response = $this->rpc(
            self::SERVICE,
            'ListSIPTrunk',
            new ListSIPTrunkRequest(),
            ListSIPTrunkResponse::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        $items = [];
        foreach ($response->getItems() as $item) {
            // RepeatedField's iterator carries no generic value type, so this
            // yields mixed — unlike the rpc() return, which the analyser infers.
            assert($item instanceof SIPTrunkInfo);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Deletes a SIP trunk, inbound or outbound.
     *
     * The rpc returns the legacy livekit.SIPTrunkInfo shape for both trunk kinds; that
     * message is deprecated upstream but is still this rpc's response type.
     */
    public function deleteSipTrunk(string $sipTrunkId): SIPTrunkInfo
    {
        $request = new DeleteSIPTrunkRequest();
        $request->setSipTrunkId($sipTrunkId);

        $response = $this->rpc(
            self::SERVICE,
            'DeleteSIPTrunk',
            $request,
            SIPTrunkInfo::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response;
    }

    /**
     * Creates a SIP dispatch rule.
     *
     * livekit.CreateSIPDispatchRuleRequest carries the rule twice: the newer nested
     * `dispatch_rule` (field 10, a full SIPDispatchRuleInfo) and the older flat fields
     * 1-9, which are marked `deprecated` in the proto but are still what the server reads
     * and still what the Node SDK v2.19.0 sends. We send the flat fields so the PHP and
     * Node clients produce byte-identical requests.
     *
     * @param SIPDispatchRule $rule one of dispatch_rule_direct, dispatch_rule_individual
     *                              or dispatch_rule_callee
     */
    public function createSipDispatchRule(
        SIPDispatchRule $rule,
        ?CreateSipDispatchRuleOptions $opts = null,
    ): SIPDispatchRuleInfo {
        $request = new CreateSIPDispatchRuleRequest();
        $request->setRule($rule);

        if ($opts !== null) {
            if ($opts->trunkIds !== null) {
                $request->setTrunkIds($opts->trunkIds);
            }
            if ($opts->hidePhoneNumber !== null) {
                $request->setHidePhoneNumber($opts->hidePhoneNumber);
            }
            if ($opts->inboundNumbers !== null) {
                $request->setInboundNumbers($opts->inboundNumbers);
            }
            if ($opts->name !== null) {
                $request->setName($opts->name);
            }
            if ($opts->metadata !== null) {
                $request->setMetadata($opts->metadata);
            }
            if ($opts->attributes !== null) {
                $request->setAttributes($opts->attributes);
            }
            if ($opts->roomPreset !== null) {
                $request->setRoomPreset($opts->roomPreset);
            }
            if ($opts->roomConfig !== null) {
                $request->setRoomConfig($opts->roomConfig);
            }
        }

        $response = $this->rpc(
            self::SERVICE,
            'CreateSIPDispatchRule',
            $request,
            SIPDispatchRuleInfo::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response;
    }

    /**
     * Replaces a SIP dispatch rule wholesale. Fields left unset on $rule are cleared.
     * Use updateSipDispatchRuleFields() to change only some fields.
     */
    public function updateSipDispatchRule(
        string $sipDispatchRuleId,
        SIPDispatchRuleInfo $rule,
    ): SIPDispatchRuleInfo {
        $request = new UpdateSIPDispatchRuleRequest();
        $request->setSipDispatchRuleId($sipDispatchRuleId);
        $request->setReplace($rule);

        $response = $this->rpc(
            self::SERVICE,
            'UpdateSIPDispatchRule',
            $request,
            SIPDispatchRuleInfo::class,
            $this->authHeader(new VideoGrant(), new SIPGrant(admin: true)),
        );

        return $response;
    }
}
