/**
 * Die Datenformen von peppermint/vault, wie der PHP-Teil sie liefert.
 * Gleiche Version wie das Composer-Paket — beide werden zusammen gehoben.
 */

/** `user:{id}` (persoenlich) oder `area:{key}` (geteilt). */
export type Bereichsschluessel = `user:${number}` | `area:${string}`;

export interface Baumeintrag {
    name: string;
    path: string;
    type: 'file' | 'folder';
    children?: Baumeintrag[];
}

export interface Suchtreffer {
    id: string;
    title: string;
    path: string;
    excerpt: string;
    url: string;
}

export interface Version {
    id: number;
    scope: Bereichsschluessel;
    path: string;
    storage_path: string | null;
    checksum: string | null;
    author: string | null;
    size: number;
    created_at: string;
}

export interface Papierkorbeintrag {
    id: number;
    scope: Bereichsschluessel;
    original_path: string;
    type: 'file' | 'folder';
    size: number | null;
    deleted_at: string;
    deleted_by: { id: number; name: string } | null;
}

/** Feste ID einer Datei (ULID), bleibt ueber Umbenennen und Papierkorb. */
export interface Vaultdatei {
    id: string;
    scope: Bereichsschluessel;
    path: string;
    checksum: string | null;
    size: number | null;
    art: string | null;
}

export interface Verknuepfung {
    id: number;
    file_id: string;
    linkable_type: string;
    linkable_id: string;
    rolle: 'notiz' | 'anhang' | string;
    angeheftet: boolean;
}

export interface Verweisgraph {
    nodes: { id: string; label: string; path: string; linkCount: number }[];
    edges: { source: string; target: string }[];
}

/** Ist das eine Binaerversion (Inhalt auf der Platte statt im Text)? */
export function istBinaerversion(version: Pick<Version, 'storage_path'>): boolean {
    return version.storage_path !== null && version.storage_path !== '';
}
