const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminGuildAlignmentsModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminGuildAlignments !== 'undefined'
                && globalWindow.AdminGuildAlignments
                && typeof globalWindow.AdminGuildAlignments.init === 'function') {
                globalWindow.AdminGuildAlignments.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminGuildAlignmentsModuleFactory = createAdminGuildAlignmentsModule;
export { createAdminGuildAlignmentsModule as AdminGuildAlignmentsModuleFactory };
export default createAdminGuildAlignmentsModule;

