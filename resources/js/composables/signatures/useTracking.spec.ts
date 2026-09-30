import { ORIGIN, resetSignatureIds, sig } from '@/components/map/trackingSignatureFixtures';
import type { TCharacterJumpedEvent } from '@/composables/useUserEvents';
import type { TSignature } from '@/types/models';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

const mocks = vi.hoisted(() => ({
    onJump: null as ((event: TCharacterJumpedEvent) => void) | null,
    update: vi.fn(),
    visit: vi.fn(),
    createTracking: vi.fn(),
}));

const settings = ref({
    is_tracking: true,
    tracking_allowed: true,
    prompt_for_signature_enabled: true,
    follow_character_enabled: true,
    copy_bookmark_enabled: false,
    suggest_alias_enabled: false,
});
const signatures = ref<TSignature[]>([]);

vi.mock('@/composables/useUserEvents', () => ({
    useCharacterJumpedEvents: (callback: (event: TCharacterJumpedEvent) => void) => {
        mocks.onJump = callback;
    },
}));
vi.mock('@/composables/useActiveMapCharacter', () => ({
    useActiveMapCharacter: () => ref({ id: 100 }),
}));
vi.mock('@/composables/useMapUserSettings', () => ({
    useMapUserSettings: () => settings,
}));
vi.mock('@/composables/useShowMap', () => ({
    useShowMap: () => ({ props: { map: { id: 7, slug: 'test-map' } } }),
}));
vi.mock('@/composables/useTrackingSystems', async () => {
    const { computed } = await import('vue');
    return {
        useTrackingSystems: () => ({
            origin_map_solarsystem: computed(() => ({ ...ORIGIN, signatures: signatures.value })),
            target_solarsystem: computed(() => ({ id: 31000002, name: 'J145510', class: '4' })),
            update: mocks.update,
        }),
    };
});
vi.mock('@/map/api', () => ({
    createTracking: mocks.createTracking,
    updateMapUserSettings: vi.fn(),
    useMapSolarsystems: () => ({ map_solarsystems: ref([]) }),
}));
vi.mock('@/routes/maps', () => ({
    show: () => ({ url: '/maps/test-map' }),
}));
vi.mock('@inertiajs/vue3', () => ({
    router: { visit: mocks.visit },
}));

const { useTracking } = await import('@/composables/signatures/useTracking');

function jump(event: Partial<TCharacterJumpedEvent> = {}): void {
    mocks.onJump!({
        map_id: 7,
        character_id: 100,
        from_solarsystem_id: 31000001,
        to_solarsystem_id: 31000002,
        ship_type_id: 73791,
        map_connection_id: 42,
        ...event,
    });
}

beforeEach(() => {
    vi.clearAllMocks();
    resetSignatureIds();
    mocks.update.mockImplementation((_origin: number, _target: number, callback: () => void) => callback());
    signatures.value = [sig({ signatureId: 'AAA-111', category: 'wormhole', targetClass: '4' })];
});

describe('useTracking', () => {
    it('ignores jumps on other maps and of other characters', () => {
        const { show_signature_modal } = useTracking();

        jump({ map_id: 8 });
        jump({ character_id: 200 });

        expect(mocks.update).not.toHaveBeenCalled();
        expect(show_signature_modal.value).toBe(false);
    });

    it('offers to link a signature to the connection the backend created', () => {
        const { show_signature_modal } = useTracking();

        jump();

        expect(mocks.update).toHaveBeenCalledWith(31000001, 31000002, expect.any(Function));
        expect(show_signature_modal.value).toBe(true);
        expect(mocks.createTracking).not.toHaveBeenCalled();
        expect(mocks.visit).not.toHaveBeenCalled();
    });

    it('still prompts when another character created the connection, as long as no signature is linked', () => {
        // AAA leads to an unrelated connection (id 1), not the jumped one.
        signatures.value = [sig({ signatureId: 'AAA-111', category: 'wormhole', targetClass: '4', connectedToId: 3 })];
        signatures.value.push(sig({ signatureId: 'BBB-222' }));
        const { show_signature_modal } = useTracking();

        jump({ map_connection_id: 42 });

        expect(show_signature_modal.value).toBe(true);
    });

    it('only follows the pilot once a signature is linked to the connection', () => {
        const linked = sig({ signatureId: 'BBB-222', category: 'wormhole', targetClass: '4', connectedToId: 2 });
        signatures.value.push(linked);
        const { show_signature_modal } = useTracking();

        jump({ map_connection_id: linked.map_connection_id! });

        expect(show_signature_modal.value).toBe(false);
        expect(mocks.visit).toHaveBeenCalledOnce();
    });

    it('only follows the pilot when the prompt is disabled', () => {
        settings.value.prompt_for_signature_enabled = false;
        const { show_signature_modal } = useTracking();

        jump();

        expect(show_signature_modal.value).toBe(false);
        expect(mocks.visit).toHaveBeenCalledOnce();
        settings.value.prompt_for_signature_enabled = true;
    });

    it('links the chosen signature through the tracking endpoint, then follows', () => {
        mocks.createTracking.mockImplementation((_from: number, _to: number, _options: unknown, onSuccess: () => void) => onSuccess());
        const { handleSelectSignature } = useTracking();
        jump();

        handleSelectSignature({ signatureId: 1, alias: null, lifetime: 'healthy', massStatus: 'fresh', shipSize: null });

        expect(mocks.createTracking).toHaveBeenCalledWith(
            ORIGIN.id,
            31000002,
            { signature_id: 1, alias: null, lifetime: 'healthy', mass_status: 'fresh', ship_size: null },
            expect.any(Function),
        );
        expect(mocks.visit).toHaveBeenCalledOnce();
    });

    it('leaves the connection untouched when the prompt is dismissed, and follows', () => {
        const { handleDismissSignature, show_signature_modal } = useTracking();
        jump();

        handleDismissSignature();

        expect(show_signature_modal.value).toBe(false);
        expect(mocks.createTracking).not.toHaveBeenCalled();
        expect(mocks.visit).toHaveBeenCalledOnce();
    });
});
