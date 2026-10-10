import { useActiveMapCharacter } from '@/composables/useActiveMapCharacter';
import { useMapUserSettings } from '@/composables/useMapUserSettings';
import { useShowMap } from '@/composables/useShowMap';
import { useTrackingSystems } from '@/composables/useTrackingSystems';
import { useCharacterJumpedEvents } from '@/composables/useUserEvents';
import { aliasTargetKind, suggestAlias } from '@/lib/alias';
import { buildSignatureBookmark } from '@/lib/bookmark';
import { groupSignatureOptions } from '@/lib/signatureCompatibility';
import { isWormholeSystem } from '@/lib/solarsystem';
import { createTracking, updateMapUserSettings, useMapSolarsystems } from '@/map/api';
import { show } from '@/routes/maps';
import { TLifetimeStatus, TMassStatus, TShipSize, TSignature } from '@/types/models';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

export function useTracking() {
    const character = useActiveMapCharacter();
    const map_user_settings = useMapUserSettings();
    const page = useShowMap();
    const { map_solarsystems } = useMapSolarsystems();

    const is_tracking = computed(() => map_user_settings.value?.is_tracking && character.value && map_user_settings.value?.tracking_allowed);
    const is_tracking_allowed = computed(() => map_user_settings.value.tracking_allowed);
    const can_track = computed(() => character.value && map_user_settings.value.tracking_allowed);

    const { origin_map_solarsystem, target_solarsystem, update } = useTrackingSystems();

    const show_signature_modal = ref(false);
    const jumped_connection_id = ref<number | null>(null);
    // All of the origin's signatures; the dialog demotes the ones that cannot
    // lead to the target instead of hiding them.
    const signatures = computed(() => origin_map_solarsystem.value?.signatures?.toSorted(sortSignatures));
    const possible_signatures = computed(() => groupSignatureOptions(signatures.value ?? [], target_solarsystem.value?.class).likely);
    const existing_map_solarsystem = computed(() => map_solarsystems.value.find((s) => s.solarsystem_id === target_solarsystem.value?.id));

    const known_aliases = computed(() => map_solarsystems.value.map((s) => s.alias).filter((alias): alias is string => Boolean(alias)));

    // Pre-fill the signature dialog's alias field. An alias the target already
    // carries on the map wins; otherwise we guess the next chain alias.
    const suggested_alias = computed(() => {
        if (existing_map_solarsystem.value?.alias) {
            return existing_map_solarsystem.value.alias;
        }

        if (!map_user_settings.value.suggest_alias_enabled) return null;

        const origin = origin_map_solarsystem.value;
        const target = target_solarsystem.value;
        if (!origin || !target) return null;

        const targetIsWormhole = isWormholeSystem(target);

        return suggestAlias({
            parentAlias: origin.alias,
            targetIsWormhole,
            originIsWormhole: isWormholeSystem(origin.solarsystem),
            aliases: known_aliases.value,
            scheme: page.props.map.bookmark_alias_scheme,
            targetKind: aliasTargetKind(targetIsWormhole, target.class),
            ignoredAlias: page.props.map.bookmark_ignored_alias,
        });
    });

    // The backend detects jumps and creates the connection; the tab only follows
    // the pilot and offers to link a signature. Alts are mapped by the backend
    // too, but the UI only reacts to the active character.
    useCharacterJumpedEvents((event) => {
        if (event.map_id !== page.props.map.id) return;
        if (event.character_id !== character.value?.id) return;

        jumped_connection_id.value = event.map_connection_id;
        update(event.from_solarsystem_id, event.to_solarsystem_id, () => performJump(event.to_solarsystem_id));
    });

    // Follow the pilot: select the system the character jumped into, so the
    // signature panel and details follow it.
    //
    // Always called once the system is known to be on the map — either it
    // already was, or the tracking request that added it has come back. That
    // ordering matters: the requests a jump fires off (the tracking lookup, the
    // tracking POST) carry the pre-jump URL, and whichever lands last decides
    // what the page URL is. Selecting after them, rather than racing them, is
    // what keeps the selection from being reverted.
    function followInto(solarsystem_id: number) {
        if (!map_user_settings.value.follow_character_enabled) return;

        router.visit(show(page.props.map.slug, { mergeQuery: { solarsystem_id } }).url, {
            preserveScroll: true,
            preserveState: true,
            only: ['map', 'selected_map_solarsystem', 'map_navigation', 'map_characters', 'eve_scout_connections', 'threat_analysis'],
        });
    }

    function sortSignatures(a: TSignature, b: TSignature) {
        if (!a.signature_id || !b.signature_id) return 0;
        return a.signature_id.localeCompare(b.signature_id);
    }

    function performJump(target_solarsystem_id: number) {
        // Whoever created the connection (this character, an alt or a
        // fleetmate), the prompt is offered as long as no signature is linked.
        const signature_linked = signatures.value?.some((s) => s.map_connection_id === jumped_connection_id.value) ?? false;

        if (signature_linked || !possible_signatures.value.length || !map_user_settings.value.prompt_for_signature_enabled) {
            followInto(target_solarsystem_id);

            return;
        }

        // Following waits for the prompt to close, so the selection is not
        // reverted by the tracking request it fires.
        show_signature_modal.value = true;
    }

    function handleDismissSignature() {
        show_signature_modal.value = false;
        if (target_solarsystem.value) followInto(target_solarsystem.value.id);
    }

    function handleToggle() {
        if (!map_user_settings.value.tracking_allowed) return;

        updateMapUserSettings(page.props.map.slug, {
            is_tracking: !map_user_settings.value.is_tracking,
        });
    }

    const follow_enabled = computed(() => map_user_settings.value.follow_character_enabled);

    function handleToggleFollow() {
        updateMapUserSettings(page.props.map.slug, {
            follow_character_enabled: !map_user_settings.value.follow_character_enabled,
        });
    }

    function handleSelectSignature(selection: {
        signatureId: number | null;
        alias: string | null;
        lifetime: TLifetimeStatus;
        massStatus: TMassStatus;
        shipSize: TShipSize | null;
    }) {
        show_signature_modal.value = false;
        if (!origin_map_solarsystem.value || !target_solarsystem.value) return;

        const target_solarsystem_id = target_solarsystem.value.id;

        copyConnectionBookmark(selection.signatureId, selection.alias);
        createTracking(
            origin_map_solarsystem.value.id,
            target_solarsystem_id,
            {
                signature_id: selection.signatureId,
                alias: selection.alias,
                lifetime: selection.lifetime,
                mass_status: selection.massStatus,
                ship_size: selection.shipSize,
            },
            () => followInto(target_solarsystem_id),
        );
    }

    // Copy the connection bookmark for the system we just jumped into, using the
    // same scheme as the connection context menu: the current system labelled
    // with the signature we used in the origin. Delegates to the shared
    // signature-bookmark builder: an empty chosen alias falls back to the
    // guessed one only when the jump is eligible for a suggestion (wormhole
    // target, or an origin that is part of the chain), otherwise the alias
    // token stays blank.
    function copyConnectionBookmark(signatureId: number | null, alias: string | null) {
        if (!map_user_settings.value.copy_bookmark_enabled) return;
        const target = target_solarsystem.value;
        if (!target) return;

        const signature = signatures.value?.find((s) => s.id === signatureId) ?? null;
        const name = buildSignatureBookmark({
            signature: {
                signature_id: signature?.signature_id ?? null,
                ship_size: signature?.ship_size ?? null,
                mass_status: signature?.mass_status ?? null,
                lifetime: signature?.lifetime ?? 'healthy',
                wormhole: signature?.wormhole,
                signature_type: signature?.signature_type,
            },
            currentSystem: { alias: origin_map_solarsystem.value?.alias, class: origin_map_solarsystem.value?.solarsystem?.class },
            connectionTarget: { alias, occupier_alias: existing_map_solarsystem.value?.occupier_alias, solarsystem: target },
            aliases: known_aliases.value,
            formats: page.props.map,
        });

        navigator.clipboard.writeText(name);
        toast.success('Copied bookmark to clipboard', { description: name });
    }

    return {
        toggle: handleToggle,
        toggle_follow: handleToggleFollow,
        follow_enabled,
        is_tracking,
        is_tracking_allowed,
        can_track,
        signatures,
        show_signature_modal,
        handleSelectSignature,
        handleDismissSignature,
        origin_map_solarsystem,
        target_solarsystem,
        existing_map_solarsystem,
        suggested_alias,
    };
}
