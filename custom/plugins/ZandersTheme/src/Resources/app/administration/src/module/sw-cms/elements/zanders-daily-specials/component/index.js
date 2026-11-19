import template from './sw-cms-el-zanders-daily-specials.html.twig';
import './sw-cms-el-zanders-daily-specials.scss';

const { Mixin } = Shopware;

export default {
    template,
    mixins: [
        Mixin.getByName('cms-element'),
    ],
};
