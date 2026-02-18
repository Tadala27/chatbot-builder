export interface menu {
  header?: string;
  title?: string;
  to?: string;
  chip?: string;
  chipColor?: string;
  chipVariant?: string;
}

const sidebarItem: menu[] = [
  { header: "Inputs" },
  {
    title: "Autocomplete",
    to: "/forms/components/autocomplete",
  },
  {
    title: "Buttons",
    to: "/forms/components/buttons",
  },

  {
    title: "Checkbox",
    to: "/forms/components/checkbox",
  },
  {
    title: "Radio",
    to: "/forms/components/radio",
  },
  {
    title: "Ratings",
    to: "/ui-elements/advance/ratings",
  },
  {
    title: "Switch",
    to: "/forms/components/switch",
  },
  {
    title: "Slider",
    to: "/forms/components/slider",
  },
  {
    title: "TextField",
    to: "/forms/components/textfield",
  },
  { header: "Data Display" },
  {
    title: "Avatar",
    to: "/ui-elements/basic/avatar",
  },
  {
    title: "Badges",
    to: "/ui-elements/basic/badges",
  },
  {
    title: "Chip",
    to: "/ui-elements/basic/chip",
  },
  {
    title: "List",
    to: "/ui-elements/basic/uilist",
  },
  {
    title: "Tooltip",
    to: "/forms/plugins/tooltip",
  },
  {
    title: "Typography",
    to: "/utilities/typography",
  },
  { header: "Feedback" },
  {
    title: "Alert",
    to: "/ui-elements/advance/alert",
  },
  {
    title: "Empty State",
    to: "/ui-elements/advance/emptystate",
    chip: "new",
    chipColor: "primary",
    chipVariant: "tonal",
  },
  {
    title: "Dialog",
    to: "/forms/plugins/dialog",
  },
  {
    title: "Progress",
    to: "/ui-elements/advance/progress",
  },
  {
    title: "Snackbar",
    to: "/ui-elements/advance/snackbar",
  },
  { header: "Navigation" },
  {
    title: "Breadcrumb",
    to: "/ui-elements/basic/breadcrumb",
  },
  {
    title: "Floating Action",
    to: "/ui-elements/advance/floatingaction",
    chip: "new",
    chipColor: "primary",
    chipVariant: "tonal",
  },
  {
    title: "Pagination",
    to: "/ui-elements/advance/pagination",
  },
  {
    title: "Speed Dial",
    to: "/ui-elements/advance/speeddial",
    chip: "new",
    chipColor: "primary",
    chipVariant: "tonal",
  },
  {
    title: "Tabs",
    to: "/ui-elements/basic/tabs",
  },
  { header: "Surfaces" },
  {
    title: "Accordion",
    to: "/ui-elements/basic/expansionpanel",
  },
  {
    title: "Cards",
    to: "/ui-elements/basic/cards",
  },
  { header: "Utils" },
  {
    title: "Colors",
    to: "/utilities/colors",
  },
  {
    title: "Shadows",
    to: "/utilities/shadows",
  },
  {
    title: "Timeline",
    to: "/ui-elements/advance/timeline",
  },
  {
    title: "Button Toggle",
    to: "/ui-elements/advance/buttontoggle",
  },
  {
    title: "Grid",
    to: "/utilities/grid",
  },
  {
    title: "Animation",
    to: "/utilities/animation",
  },
];
export default sidebarItem;
