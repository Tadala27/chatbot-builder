'use strict'

// ------------------------------------------------------------------------------
// Requirements
// ------------------------------------------------------------------------------

// eslint-disable-next-line @typescript-eslint/no-var-requires
const utils = require('eslint-plugin-vue/lib/utils')

function toCamelCase(string_) {
  return string_.toLowerCase().replace(/[^a-z\d]+(.)/gi, (m, chr) => chr.toUpperCase())
}

// ------------------------------------------------------------------------------
// Rule Definition
// ------------------------------------------------------------------------------

module.exports = {
  meta: {
    type: 'problem',
    docs: {
      description: 'require valid prop for "AppCardCode" component',
      categories: ['base'],
      url: 'https://eslint.vuejs.org/rules/require-component-is.html',
    },
    fixable: null,
    schema: [],
  },

  /** @param {RuleContext} context */
  create(context) {
    return utils.defineTemplateBodyVisitor(context, {
      /** @param {VElement} node */
      'VElement[name=\'appcardcode\']': function (node) {
        const propertyTitle = utils.getAttribute(node, 'title')
        const propertyCode = utils.getDirective(node, 'bind', 'code')

        const titleValue = propertyTitle.value.value
        const demoCodeProperty = propertyCode.value.expression.property.name

        const camelCasedTitle = toCamelCase(titleValue)

        if (camelCasedTitle !== demoCodeProperty) {
          context.report({
            node,
            loc: propertyCode.value.expression.property.loc,
            message: `Expected 'code' prop value to match the camelcase value of title prop value. Expected: '${camelCasedTitle}', Actual: '${demoCodeProperty}'`,
          })
        }
      },
    })
  },
}
