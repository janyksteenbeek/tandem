export type Branding = {
    name: string;
    tagline: string | null;
    logo: string | null;
    logo_dark: string | null;
    auth: {
        quote: string | null;
        attribution: string | null;
        perks: string[];
        footnote: string | null;
    };
};
