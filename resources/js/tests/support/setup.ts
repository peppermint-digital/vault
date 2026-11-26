/** Was jsdom nicht mitbringt: ResizeObserver brauchen die Radix-Bausteine. */
if (!('ResizeObserver' in globalThis)) {
    (globalThis as Record<string, unknown>).ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
}
