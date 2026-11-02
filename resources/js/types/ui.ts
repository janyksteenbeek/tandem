export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type ReverbConnection = {
    key: string;
    host: string | null;
    port: number;
    scheme: 'http' | 'https';
};
