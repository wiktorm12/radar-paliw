export const BREAKPOINTS = {
    SM: 640,
    MD: 768,
    LG: 1024,
    XL: 1280,
    XXL: 1536,
};

export const isBreakpointUp = (breakpoint) => window.matchMedia(`(min-width: ${breakpoint}px)`).matches;
