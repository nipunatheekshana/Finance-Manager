import type { Component } from 'vue'
import {
  Apple, Baby, BadgePercent, Banknote, Bath, BatteryCharging, Bed, Beer, Bike, Bone,
  BookOpen, Brain, Briefcase, Brush, Building2, Bus, Cable, Cake, Calculator, Camera,
  Car, Carrot, Cat, Church, Cigarette, Circle, CircleEllipsis, Clapperboard, Cloud,
  Coffee, Coins, CreditCard, Cross, Dice5, Dog, Drama, Droplets, Dumbbell, Film, Fish,
  Flame, Flower2, Footprints, Fuel, Gamepad2, Gem, Gift, Glasses, Globe, GraduationCap,
  Guitar, Hammer, HandCoins, Headphones, Heart, HeartPulse, Home, Hotel, IceCreamCone,
  Key, Landmark, Laptop, Leaf, Library, Lightbulb, Lock, Mail, MapPin, Microwave,
  Monitor, Moon, Mountain, Music, Newspaper, Package, Palette, PartyPopper, Percent,
  Phone, Piano, PiggyBank, Pill, Pizza, Plane, Plug, Printer, Puzzle, Receipt, Recycle,
  Refrigerator, Repeat, Ribbon, Router, School, Scissors, Shield, Ship, Shirt,
  ShoppingBag, ShoppingCart, Smartphone, Snowflake, Sofa, Sparkles, Split, Sprout, Star,
  Stethoscope, Store, Sun, Syringe, Tablet, Tent, Ticket, TrainFront, TreePine, Trees,
  Trophy, Truck, Tv, Umbrella, User, Users, Utensils, Volleyball, Wallet, WashingMachine,
  Watch, Waves, Wifi, Wine, Wrench, Zap,
} from 'lucide-vue-next'

/**
 * One list, used by both the picker and the renderer, so a category can never
 * be given an icon the app does not know how to draw.
 *
 * Grouped for the picker; the flat map is what CategoryIcon looks up by the
 * name stored on the category.
 */
export const ICON_GROUPS: Array<{ label: string; icons: Record<string, Component> }> = [
  {
    label: 'Food & drink',
    icons: {
      utensils: Utensils, coffee: Coffee, pizza: Pizza, cake: Cake, 'ice-cream-cone': IceCreamCone,
      apple: Apple, carrot: Carrot, fish: Fish, beer: Beer, wine: Wine,
    },
  },
  {
    label: 'Home',
    icons: {
      home: Home, bed: Bed, sofa: Sofa, bath: Bath, lightbulb: Lightbulb, plug: Plug,
      'washing-machine': WashingMachine, refrigerator: Refrigerator, microwave: Microwave,
      wrench: Wrench, hammer: Hammer, key: Key, lock: Lock,
    },
  },
  {
    label: 'Bills & utilities',
    icons: {
      receipt: Receipt, zap: Zap, droplets: Droplets, flame: Flame, wifi: Wifi, router: Router,
      phone: Phone, smartphone: Smartphone, cable: Cable, 'battery-charging': BatteryCharging,
      repeat: Repeat, 'circle-ellipsis': CircleEllipsis,
    },
  },
  {
    label: 'Getting around',
    icons: {
      car: Car, fuel: Fuel, bus: Bus, 'train-front': TrainFront, bike: Bike, plane: Plane,
      ship: Ship, truck: Truck, 'map-pin': MapPin, footprints: Footprints,
    },
  },
  {
    label: 'Shopping',
    icons: {
      'shopping-bag': ShoppingBag, 'shopping-cart': ShoppingCart, store: Store, shirt: Shirt,
      glasses: Glasses, watch: Watch, gem: Gem, gift: Gift, package: Package, 'badge-percent': BadgePercent,
    },
  },
  {
    label: 'Fun',
    icons: {
      clapperboard: Clapperboard, film: Film, tv: Tv, 'gamepad-2': Gamepad2, 'dice-5': Dice5,
      puzzle: Puzzle, music: Music, guitar: Guitar, piano: Piano, headphones: Headphones,
      camera: Camera, ticket: Ticket, 'party-popper': PartyPopper, drama: Drama, palette: Palette,
      brush: Brush,
    },
  },
  {
    label: 'Health & body',
    icons: {
      'heart-pulse': HeartPulse, stethoscope: Stethoscope, pill: Pill, syringe: Syringe,
      cross: Cross, brain: Brain, dumbbell: Dumbbell, volleyball: Volleyball, scissors: Scissors,
      cigarette: Cigarette,
    },
  },
  {
    label: 'People & life',
    icons: {
      user: User, users: Users, baby: Baby, heart: Heart, dog: Dog, cat: Cat, bone: Bone,
      ribbon: Ribbon, church: Church, sparkles: Sparkles, star: Star, trophy: Trophy,
    },
  },
  {
    label: 'Learning & work',
    icons: {
      'book-open': BookOpen, 'graduation-cap': GraduationCap, school: School, library: Library,
      briefcase: Briefcase, laptop: Laptop, monitor: Monitor, tablet: Tablet, printer: Printer,
      newspaper: Newspaper, mail: Mail, calculator: Calculator, globe: Globe,
    },
  },
  {
    label: 'Travel & outdoors',
    icons: {
      hotel: Hotel, tent: Tent, mountain: Mountain, waves: Waves, 'tree-pine': TreePine,
      trees: Trees, leaf: Leaf, sprout: Sprout, 'flower-2': Flower2, sun: Sun, moon: Moon,
      cloud: Cloud, snowflake: Snowflake, umbrella: Umbrella, recycle: Recycle,
    },
  },
  {
    label: 'Money',
    icons: {
      wallet: Wallet, banknote: Banknote, coins: Coins, 'hand-coins': HandCoins, 'piggy-bank': PiggyBank,
      'credit-card': CreditCard, landmark: Landmark, 'building-2': Building2, percent: Percent,
      shield: Shield, split: Split, circle: Circle,
    },
  },
]

/** Every icon by name, whichever group it sits in. */
export const CATEGORY_ICONS: Record<string, Component> = Object.assign(
  {},
  ...ICON_GROUPS.map((group) => group.icons),
)

/**
 * Tailwind's full hue range, plus the greys. Each entry is a literal class
 * string so the build can see and generate it; a colour the renderer does not
 * know falls back to slate.
 */
export const CATEGORY_COLORS: Record<string, string> = {
  red: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400',
  orange: 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400',
  amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
  yellow: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-400',
  lime: 'bg-lime-100 text-lime-700 dark:bg-lime-500/15 dark:text-lime-400',
  green: 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-400',
  emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
  teal: 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-400',
  cyan: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-400',
  sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400',
  blue: 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
  indigo: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-400',
  violet: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-400',
  purple: 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-400',
  fuchsia: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-500/15 dark:text-fuchsia-400',
  pink: 'bg-pink-100 text-pink-700 dark:bg-pink-500/15 dark:text-pink-400',
  rose: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
  slate: 'bg-slate-100 text-slate-700 dark:bg-slate-500/15 dark:text-slate-300',
  gray: 'bg-gray-100 text-gray-700 dark:bg-gray-500/15 dark:text-gray-300',
  zinc: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-500/15 dark:text-zinc-300',
  neutral: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-500/15 dark:text-neutral-300',
  stone: 'bg-stone-100 text-stone-700 dark:bg-stone-500/15 dark:text-stone-300',
}

/** The solid swatch shown in the colour picker. */
export const CATEGORY_SWATCHES: Record<string, string> = {
  red: 'bg-red-500', orange: 'bg-orange-500', amber: 'bg-amber-500', yellow: 'bg-yellow-400',
  lime: 'bg-lime-500', green: 'bg-green-500', emerald: 'bg-emerald-500', teal: 'bg-teal-500',
  cyan: 'bg-cyan-500', sky: 'bg-sky-500', blue: 'bg-blue-500', indigo: 'bg-indigo-500',
  violet: 'bg-violet-500', purple: 'bg-purple-500', fuchsia: 'bg-fuchsia-500', pink: 'bg-pink-500',
  rose: 'bg-rose-500', slate: 'bg-slate-500', gray: 'bg-gray-500', zinc: 'bg-zinc-500',
  neutral: 'bg-neutral-500', stone: 'bg-stone-500',
}
