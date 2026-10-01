import { useOnClient } from '@/composables/useOnClient';
import useUser from '@/composables/useUser';
import { getUserChannelName } from '@/const/channels';
import { CharacterJumpedEvent, UserCharacterStatusUpdatedEvent } from '@/const/events';
import { router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';

/** A tracked character jumped through a wormhole connection the backend created or reused. */
export type TCharacterJumpedEvent = {
    map_id: number;
    character_id: number;
    from_solarsystem_id: number;
    to_solarsystem_id: number;
    ship_type_id: number | null;
    map_connection_id: number;
};

/**
 * Subscribe to the authenticated user's private channel and refresh the shared
 * `auth` prop whenever one of their characters' status changes. This keeps the
 * character list (e.g. online state in context menus) current even for
 * characters that are not on the map being viewed.
 */
export function useUserEvents() {
    useUserChannel(UserCharacterStatusUpdatedEvent, () => {
        router.reload({ only: ['auth'] });
    });
}

/**
 * Run the callback for every jump of the user's characters, on any map.
 */
export function useCharacterJumpedEvents(callback: (event: TCharacterJumpedEvent) => void) {
    useUserChannel(CharacterJumpedEvent, callback);
}

function useUserChannel<T>(event: string, callback: (payload: T) => void) {
    const user = useUser();

    useOnClient(() => {
        const userId = user.value?.id;
        if (!userId) {
            return;
        }

        useEcho<T>(getUserChannelName(userId), event, callback);
    });
}
