const globalWindow = (typeof window !== 'undefined') ? window : globalThis;

function createAdminLogsExperienceModule() {
    return {
        mount: function () {
            if (typeof globalWindow.AdminLogsExperience !== 'undefined'
                && globalWindow.AdminLogsExperience
                && typeof globalWindow.AdminLogsExperience.init === 'function') {
                globalWindow.AdminLogsExperience.init();
            }
        },
        unmount: function () {}
    };
}

globalWindow.AdminLogsExperienceModuleFactory = createAdminLogsExperienceModule;
export { createAdminLogsExperienceModule as AdminLogsExperienceModuleFactory };
export default createAdminLogsExperienceModule;

