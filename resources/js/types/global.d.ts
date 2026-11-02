import type { Directive } from 'vue';
import type { Auth, Sso } from '@/types/auth';
import type { Branding } from '@/types/branding';
import type { ComposePrefill, MailNav } from '@/types/mail';
import type {
    Organization,
    PendingInvitation,
} from '@/types/organizations';
import type { CreatedChannel } from '@/types/settings';
import type { FlashToast, ReverbConnection } from '@/types/ui';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            branding: Branding;
            auth: Auth;
            sso: Sso;
            reverb: ReverbConnection | null;
            currentOrganization: Organization | null;
            organizations: Organization[];
            mailNav: MailNav | null;
            unreadNotifications: number;
            canManageOrganization: boolean;
            pendingInvitations: PendingInvitation[];
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: FlashToast;
            compose?: ComposePrefill;
            channel?: CreatedChannel;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
