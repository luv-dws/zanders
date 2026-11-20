import template from './zanders-quantity-adjustment-config.html.twig';
import './zanders-quantity-adjustment-config.scss';

Shopware.Component.register('zanders-quantity-adjustment-config', {
    template,

    props: {
        value: {
            type: [String, Array],
            required: false,
            default: null,
        },
        label: {
            type: [String, Object],
            required: false,
            default: null,
        },
    },

    data() {
        const normalizedRules = this.normalizeRules(this.value);
        return {
            rules: normalizedRules.length > 0 ? normalizedRules : [this.createBlankRule()],
        };
    },

    watch: {
        value(value) {
            const incoming = this.normalizeRules(value);
            if (JSON.stringify(incoming) !== JSON.stringify(this.rules)) {
                this.rules = incoming;
            }
        },
    },

    methods: {
        createBlankRule() {
            return {
                qtyFrom: null,
                qtyTo: null,
                startFrom: '',
                startTo: '',
                step: 1,
                display: '',
            };
        },

        normalizeRules(value) {
            if (Array.isArray(value)) {
                return value.map((rule) => this.normalizeRule(rule));
            }

            if (typeof value === 'string' && value.trim() !== '') {
                try {
                    const parsed = JSON.parse(value);
                    if (Array.isArray(parsed)) {
                        return parsed.map((rule) => this.normalizeRule(rule));
                    }
                    if (parsed && typeof parsed === 'object') {
                        return [this.normalizeRule(parsed)];
                    }
                } catch (error) {
                    return [];
                }
            }

            return [];
        },

        normalizeRule(rule) {
            return {
                qtyFrom: rule.qtyFrom ?? rule.qty_from ?? rule.from ?? null,
                qtyTo: rule.qtyTo ?? rule.qty_to ?? rule.to ?? null,
                startFrom: rule.startFrom ?? rule.start_from ?? '',
                startTo: rule.startTo ?? rule.start_to ?? '',
                step: rule.step ?? rule.stepValue ?? rule.value ?? 1,
                display: rule.display ?? rule.string ?? '',
            };
        },

        addRule() {
            this.rules.push({
                qtyFrom: null,
                qtyTo: null,
                startFrom: '',
                startTo: '',
                step: 1,
                display: '',
            });
            this.emitRules();
        },

        removeRule(index) {
            this.rules.splice(index, 1);
            this.emitRules();
        },

        emitRules() {
            const validRules = this.rules
                .filter((rule) => rule.qtyFrom !== null && rule.qtyTo !== null)
                .map((rule) => ({
                    qtyFrom: Number(rule.qtyFrom),
                    qtyTo: Number(rule.qtyTo),
                    startFrom: rule.startFrom || '',
                    startTo: rule.startTo || '',
                    step: Math.max(1, Number(rule.step || 1)),
                    display: rule.display || '',
                }));

            this.$emit('update:value', JSON.stringify(validRules));
        },
    },
});
