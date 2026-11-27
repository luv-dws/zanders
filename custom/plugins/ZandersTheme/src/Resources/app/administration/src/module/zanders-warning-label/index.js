import './page/zanders-warning-label-list';
import './page/zanders-warning-label-detail';

Shopware.Module.register('zanders-warning-label', {
    type: 'plugin',
    name: 'zanders-warning-label',
    title: 'Warning Labels',
    description: 'Manage warning labels and alert messages',
    color: '#F59E0B',
    icon: 'regular-exclamation-triangle',

    routes: {
        index: {
            component: 'zanders-warning-label-list',
            path: 'index'
        },
        create: {
            component: 'zanders-warning-label-detail',
            path: 'create',
            meta: { parentPath: 'zanders.warning.label.index' }
        },
        detail: {
            component: 'zanders-warning-label-detail',
            path: 'detail/:id',
            meta: { parentPath: 'zanders.warning.label.index' }
        }
    },

    navigation: [{
        label: 'Warning Labels',
        color: '#F59E0B',
        path: 'zanders.warning.label.index',
        icon: 'regular-exclamation-triangle',
        parent: 'sw-catalogue',
        position: 100
    }]
});