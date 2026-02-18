import globals from "globals";
import js from "@eslint/js";
import tseslintrule from "@typescript-eslint/eslint-plugin";
import tseslint from "@typescript-eslint/parser";
import vueeslint from "vue-eslint-parser";
import vuePlugin from "eslint-plugin-vue";
import promisePlugin from "eslint-plugin-promise";
import sonarjsPlugin from "eslint-plugin-sonarjs";
import casePolicePlugin from "eslint-plugin-case-police";
import regexPlugin from "eslint-plugin-regex";
import regexpPlugin from "eslint-plugin-regexp";
import importPlugin from "eslint-plugin-import";


/** @type {import('eslint').Linter.Config[]} */
export default [
  {
    files: ["**/*.{js,mjs,cjs,ts,tsx,vue}"],
    languageOptions: { 
      parser: tseslint,
      ecmaVersion: "latest", // ECMAScript version
      sourceType: "module", // Use "module" for ES modules
      globals: {
        ...globals.browser, // Add browser globals
        ...globals.node,    // Add Node.js globals
        useAbility: 'readonly',
        $api: 'readonly',
        useCookie: 'readonly',
        definePage: 'readonly',
        getCurrentInstance: 'readonly',
        DirAttrSet: 'readonly'
      },
    },  
    plugins: {
      vue: vuePlugin,
      eslintjs: js,
      '@typescript-eslint': tseslintrule,
      promise: promisePlugin,
      sonarjs: sonarjsPlugin,
      "case-police": casePolicePlugin,
      regexp: regexpPlugin,
      "regex": regexPlugin,
      import: importPlugin,
      "internal-rules": {
        rules: {
          "valid-appcardcode-code-prop": (await import("./eslint-internal-rules/valid-appcardcode-code-prop.js")).default,
          "valid-appcardcode-demo-sfc": (await import("./eslint-internal-rules/valid-appcardcode-demo-sfc.js")).default,
        },
      },
    },
    rules: {
      ...(js.configs.recommended?.rules || {}),
      ...(tseslintrule.configs.recommended?.rules || {}),
      ...(regexpPlugin.configs["flat/recommended"]?.rules || {}), 
      ...(vuePlugin.configs["vue3-recommended"]?.rules || {}),
      ...(importPlugin.flatConfigs.recommended?.rules || {}),
      // ignore virtual files
      'import/no-unresolved': ["error", {
        ignore: [
          '~pages$',
          'virtual:generated-layouts',
          '#auth$',
          '@db',
          '@',
          '.',
          '..',
          '@config',
          '@images',
          '@layouts',
          '@styles',
          '@core',
          '.*\\?raw', // Vite raw imports
        ],
      }],

      // Ignore _ as unused variable
      '@typescript-eslint/no-unused-vars': ['error', { varsIgnorePattern: '^_+$', argsIgnorePattern: '^_+$' }],
      '@typescript-eslint/no-explicit-any': 'off',
    },
  },
  // Add Vue-specific settings
  {
    files: ["**/*.vue"],
    languageOptions: {
      parser: vueeslint,
      parserOptions: {
        parser: tseslint,
        ecmaVersion: "latest",
        sourceType: "module",
      }
    },
    rules: {
      ...js.configs.recommended.rules,
      ...vuePlugin.configs["vue3-recommended"].rules,
      ...tseslintrule.configs.recommended?.rules,
      ...importPlugin.flatConfigs.recommended?.rules,
      ...regexpPlugin.configs["flat/recommended"]?.rules,
      'no-alert': 'off',
      'no-console': ['warn', { allow: ['error'] }],
      'no-debugger': 'warn',

      // indentation (Already present in TypeScript)
      'comma-spacing': ['error', { before: false, after: true }],
      'key-spacing': ['error', { afterColon: true }],
      'n/prefer-global/process': ['off'],
      'sonarjs/cognitive-complexity': ['off'],

      'vue/first-attribute-linebreak': ['error', {
        singleline: 'beside',
        multiline: 'below',
      }],

      'antfu/top-level-function': 'off',
      '@typescript-eslint/no-explicit-any': 'off',

      // indentation (Already present in TypeScript)
      'indent': ['error', 2],

        // Enforce trailing comma (Already present in TypeScript)
      'comma-dangle': ['error', 'always-multiline'],

      // Enforce consistent spacing inside braces of object (Already present in TypeScript)
      'object-curly-spacing': ['error', 'always'],

      // Enforce camelCase naming convention
      'camelcase': ['error', { properties: 'never' }],

      // Disable max-len
      'max-len': 'off',

      // error for semi
      'semi': ['error', 'never'],

      // add parens ony when required in arrow function
      'arrow-parens': ['error', 'as-needed'],

      // add new line above comment
      'newline-before-return': 'error',

      'array-element-newline': ['error', 'consistent'],
      'array-bracket-newline': ['error', 'consistent'],

      'vue/multi-word-component-names': 'off',

      'padding-line-between-statements': [
        'error',
        { blankLine: 'always', prev: 'expression', next: 'const' },
        { blankLine: 'always', prev: 'const', next: 'expression' },
        { blankLine: 'always', prev: 'multiline-const', next: '*' },
        { blankLine: 'always', prev: '*', next: 'multiline-const' },
      ],

      // Plugin: eslint-plugin-import
      'import/prefer-default-export': 'off',
      'import/newline-after-import': ['error', { count: 1 }],
      'no-restricted-imports': ['error', {
        name: 'vue3-apexcharts',
        message: 'apexcharts are auto imported',
      }],

      // For omitting extension for ts files
      'import/extensions': [
        'error',
        'ignorePackages',
        {
          js: 'never',
          jsx: 'never',
          ts: 'never',
          tsx: 'never'
        },
      ],

      'import/no-named-as-default': 'off',

      // Thanks: https://stackoverflow.com/a/63961972/10796681
      'no-shadow': 'off',
      '@typescript-eslint/no-shadow': ['error'],

      '@typescript-eslint/consistent-type-imports': 'error',

      // Plugin: eslint-plugin-promise
      'promise/always-return': 'off',
      'promise/catch-or-return': 'off',

      // ESLint plugin vue
      'vue/block-tag-newline': 'error',
      'vue/component-api-style': 'error',
      'vue/component-name-in-template-casing': ['error', 'PascalCase', { registeredComponentsOnly: false, ignores: ['/^swiper-/'] }],
      'vue/custom-event-name-casing': ['error', 'camelCase', {
        ignores: [
          '/^(click):[a-z]+((\d)|([A-Z0-9][a-z0-9]+))*([A-Z])?/',
        ],
      }],
      'vue/define-macros-order': 'error',
      'vue/html-comment-content-newline': 'error',
      'vue/html-comment-content-spacing': 'error',
      'vue/html-comment-indent': 'error',
      'vue/match-component-file-name': 'error',
      'vue/no-child-content': 'error',
      'vue/require-default-prop': 'off',

      'vue/no-duplicate-attr-inheritance': 'error',
      'vue/no-empty-component-block': 'error',
      'vue/no-multiple-objects-in-class': 'error',
      'vue/no-reserved-component-names': 'error',
      'vue/no-template-target-blank': 'error',
      'vue/no-useless-mustaches': 'error',
      'vue/no-useless-v-bind': 'error',
      'vue/padding-line-between-blocks': 'error',
      'vue/prefer-separate-static-class': 'error',
      'vue/prefer-true-attribute-shorthand': 'error',
      'vue/v-on-function-call': 'error',

      'vue/valid-v-slot': ['error', {
        allowModifiers: true,
      }],

      // -- Extension Rules
      'vue/no-irregular-whitespace': 'error',
      'vue/template-curly-spacing': 'error',

      // -- Sonarlint
      'sonarjs/no-duplicate-string': 'off',
      'sonarjs/no-nested-template-literals': 'off',

      // Internal Rules
      "internal-rules/valid-appcardcode-code-prop": "error",
      "internal-rules/valid-appcardcode-demo-sfc": "error",

      // https://github.com/gmullerb/eslint-plugin-regex
      'regex/invalid': [
        'error',
        [
          {
            regex: '@/assets/images',
            replacement: '@images',
            message: 'Use \'@images\' path alias for image imports',
          },
          {
            regex: '@/assets/styles',
            replacement: '@styles',
            message: 'Use \'@styles\' path alias for importing styles from \'resources/ts/assets/styles\'',
          },
          {
            regex: '@core/\\w',
            message: 'You can\'t use @core when you are in @layouts module',
            files: {
              inspect: '@layouts/.*',
            },
          },
          {
            regex: 'useLayouts\\(',
            message: '`useLayouts` composable is only allowed in @layouts & @core directory. Please use `useThemeConfig` composable instead.',
            files: {
              inspect: '^(?!.*(@core|@layouts)).*',
            },
          },
        ],
      // Ignore files
      '\.eslint\.config\.js',
     ]
    },
    "settings": {
      "import/resolver": {
        node: {
          extensions: [".js", ".jsx", ".ts", ".tsx", ".vue"],
        },
        typescript: {
          alwaysTryTypes: true,
          project: './tsconfig.json', // Ensure it's pointing to the correct tsconfig file
        },
      },
    }
  },
];