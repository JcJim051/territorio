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
    notifications: {
        unread: number;
        latest: Array<{
            id: string;
            title: string;
            message: string;
            href: string;
            category: string;
            readAt?: string;
            createdAt?: string;
        }>;
    };
    flash: { success?: string; error?: string };
    errors: Record<string, string>;
};
