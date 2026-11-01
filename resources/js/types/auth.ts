export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};

export type SsoProvider = {
    key: string;
    name: string;
};

export type Sso = {
    forced: boolean;
    providers: SsoProvider[];
};

export type SsoIdentity = {
    provider: string;
    email: string | null;
    connected_at_diff: string | null;
    last_used_at_diff: string | null;
};
