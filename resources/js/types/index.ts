import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    is_admin: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    balance: string | null;
    flash: { success: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface Generation {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: GenerationStatus;
    is_final: boolean;
    error_message: string | null;
    is_retry: boolean;
    retried_status: GenerationStatus | null;
    can_retry: boolean;
    estimate: { min: string; expected: string; max: string };
    charge: string | null;
    tokens: { input_text: number; input_image: number; output: number; reasoning: number } | null;
    image: string | null;
    image_expired: boolean;
    created_at: string;
    completed_at: string | null;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

/** Laravel's paginator as serialised by a resource collection. */
export interface ResourcePage<T> {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
}

/** Laravel's paginator serialised directly. */
export interface Paginated<T> {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    current_page: number;
    last_page: number;
    total: number;
}

export interface Estimate {
    tokens: Record<string, { min: number; expected: number; max: number }>;
    charge: { min: number; expected: number; max: number; min_formatted: string; expected_formatted: string; max_formatted: string };
    balance: string;
    can_afford: boolean;
}
