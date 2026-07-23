/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        // ARK Workbench components and layouts
        './storage/application-profiles/ark-workbench/components/**/*.disyl',
        './storage/application-profiles/ark-workbench/layouts/**/*.disyl',

        // PAL module templates
        './modules/project-audit-ledger/templates/**/*.disyl',

        // Legacy PAL templates
        './templates/modules/project-audit-ledger/**/*.disyl',

        // Other module templates that use workbench
        './modules/attendance-wage/templates/**/*.disyl',
        './modules/guidance/templates/**/*.disyl',
        './modules/wms/templates/**/*.disyl',

        // PHP handlers that may contain inline class references
        './modules/project-audit-ledger/handlers/**/*.php',

        // Kernel entity renderer emits utility classes for non-Workbench profiles
        './kernel/EntityContext/DefaultEntityRenderer.php',
    ],
    safelist: [
        // DiSyL-dynamic background classes (conditionally rendered in {if} blocks)
        { pattern: /^bg-(green|orange|red|yellow|blue|gray)-(100|400)$/ },

        // DiSyL-dynamic text color classes
        { pattern: /^text-(green|red|amber|blue|yellow)-(60[07]|700)$/ },
    ],
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#eff6ff',
                    100: '#dbeafe',
                    200: '#bfdbfe',
                    300: '#93c5fd',
                    500: '#3b82f6',
                    600: '#2563eb',
                    700: '#1d4ed8',
                    800: '#1e40af',
                    900: '#1e3a8a',
                },
                pal: {
                    50: '#eef2ff',
                    100: '#e0e7ff',
                    200: '#c7d2fe',
                    300: '#a5b4fc',
                    400: '#818cf8',
                    500: '#6366f1',
                    600: '#4f46e5',
                    700: '#4338ca',
                    800: '#3730a3',
                    900: '#312e81',
                },
            },
        },
    },
    plugins: [],
};
