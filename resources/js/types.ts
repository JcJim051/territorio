export type CampaignSummary = {
    id: number;
    name: string;
    candidateName: string;
    office: string;
    territory: string;
    electionAt?: string;
    themeColor: string;
};

export type SharedProps = {
    auth: { user: { id: number; name: string; email: string } | null };
    currentCampaign: {
        id: number;
        name: string;
        candidateName: string;
        office: string;
        territory: string;
        electionAt?: string;
        themeColor: string;
        role: string;
        permissions: string[];
        isSuperAdmin: boolean;
    } | null;
    campaigns: CampaignSummary[];
    flash: { success?: string; error?: string };
    errors: Record<string, string>;
};
