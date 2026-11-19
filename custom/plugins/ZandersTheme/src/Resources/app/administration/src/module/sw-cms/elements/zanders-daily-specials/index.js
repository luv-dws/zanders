Shopware.Component.register('sw-cms-el-preview-zanders-daily-specials', () => import('./preview'));
Shopware.Component.register('sw-cms-el-zanders-daily-specials', () => import('./component'));

Shopware.Service('cmsService').registerCmsElement({
    name: 'zanders-daily-specials',
    label: 'Zanders Daily Specials',
    component: 'sw-cms-el-zanders-daily-specials',
    previewComponent: 'sw-cms-el-preview-zanders-daily-specials',
    defaultConfig: {},
});
