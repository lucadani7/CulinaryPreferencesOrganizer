export type Role = 'user' | 'admin';
export type Diet = 'omnivore' | 'vegetarian' | 'vegan' | 'pescatarian';
export type Sentiment = 'love' | 'like' | 'neutral' | 'dislike' | 'avoid';
export type TargetType = 'product' | 'category' | 'restaurant';
export type Perishability = 'low' | 'medium' | 'high';
export type ListStatus = 'open' | 'completed' | 'archived';
export type Grade = 'a' | 'b' | 'c' | 'd' | 'e';

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Allergen {
  id: number;
  name: string;
  off_tag: string;
  products_count?: number;
  // number of users who avoid this allergen (admin taxonomy page)
  users_count?: number;
  pivot?: { level?: 'contains' | 'traces'; severity?: 'avoid' | 'strict' };
}

export interface UserAllergen {
  id: number;
  name: string;
  off_tag: string;
  severity: 'avoid' | 'strict';
}

export interface User {
  id: number;
  name: string;
  email: string;
  role: Role;
  is_active: boolean;
  diet: Diet;
  country: string | null;
  locale: string;
  last_login_at: string | null;
  created_at: string;
  allergens: UserAllergen[];
  groups: { id: number; name: string; role: 'owner' | 'member' }[];
}

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  role: Role;
  is_active: boolean;
  diet: Diet;
  country: string | null;
  last_login_at: string | null;
  created_at: string;
  preferences_count: number;
  shopping_lists_count: number;
  groups_count: number;
}

export interface Category {
  id: number;
  name: string;
  slug: string;
  parent_id: number | null;
  off_tag: string | null;
  description: string | null;
  products_count?: number;
  parent?: { id: number; name: string } | null;
}

export interface Availability {
  id?: number;
  region: string;
  month_from: number;
  month_to: number;
  is_available?: boolean;
  notes?: string | null;
}

export interface ProductImage {
  id: number;
  url: string;
  type: 'front' | 'ingredients' | 'nutrition' | 'packaging' | 'other';
  is_uploaded: boolean;
}

export interface Restaurant {
  id: number;
  name: string;
  cuisine: string | null;
  address: string | null;
  city: string | null;
  country: string | null;
  latitude: number | null;
  longitude: number | null;
  price_level: number | null;
  website: string | null;
  phone: string | null;
  has_vegetarian_options: boolean;
  has_vegan_options: boolean;
  created_by: number | null;
  products_count?: number;
  my_sentiment?: Sentiment | null;
  products?: (Pick<Product, 'id' | 'name' | 'brand' | 'image_url'> & { pivot: { price: string | null; dish_name: string | null } })[];
  pivot?: { price: string | null; dish_name: string | null };
}

export interface Product {
  id: number;
  name: string;
  brand: string | null;
  barcode: string | null;
  category_id: number | null;
  category?: Pick<Category, 'id' | 'name'> | null;
  image_url: string | null;
  price: string | null;
  currency: string;
  quantity: string | null;
  ingredients_text: string | null;
  nutriscore: Grade | null;
  nova_group: number | null;
  ecoscore: Grade | null;
  is_vegetarian: boolean | null;
  is_vegan: boolean | null;
  perishability: Perishability;
  shelf_life_days: number | null;
  expires_at: string | null;
  nutriments: Record<string, number> | null;
  source: 'manual' | 'off';
  off_synced_at: string | null;
  created_by: number | null;
  allergens?: Allergen[];
  images?: ProductImage[];
  availabilities?: Availability[];
  restaurants?: Restaurant[];
  my_sentiment?: Sentiment | null;
  has_my_allergens?: boolean;
}

export interface ProductDetail extends Product {
  compatibility: { compatible: boolean; reasons: string[] };
  my_preference: Preference | null;
  preference_stats: Partial<Record<Sentiment, number>>;
  avg_rating: number | null;
  creator?: { id: number; name: string } | null;
}

export interface OffProduct {
  barcode: string;
  name: string | null;
  brand: string | null;
  quantity: string | null;
  image_url: string | null;
  images: Record<string, string>;
  ingredients_text: string | null;
  nutriscore: Grade | null;
  nova_group: number | null;
  ecoscore: Grade | null;
  is_vegan: boolean | null;
  is_vegetarian: boolean | null;
  allergens_tags: string[];
  traces_tags: string[];
  categories_tags: string[];
  nutriments: Record<string, number> | null;
  countries: string[];
  local_id: number | null;
}

export interface OffSearchResult {
  count: number;
  page: number;
  page_size: number;
  products: OffProduct[];
}

export interface Preference {
  id: number;
  user_id: number;
  group_id: number | null;
  preferable_type: TargetType;
  preferable_id: number;
  sentiment: Sentiment;
  rating: number | null;
  notes: string | null;
  created_at: string;
  user?: { id: number; name: string };
  preferable?: (Partial<Product> & Partial<Restaurant> & { id: number; name: string }) | null;
}

export interface Group {
  id: number;
  name: string;
  description: string | null;
  owner_id: number;
  owner?: { id: number; name: string };
  members_count?: number;
  shopping_lists_count?: number;
  members?: (Pick<User, 'id' | 'name' | 'email' | 'diet'> & {
    allergens?: Pick<Allergen, 'id' | 'name'>[];
    pivot: { role: 'owner' | 'member'; joined_at: string };
  })[];
}

export interface ConsensusItem {
  type: TargetType;
  id: number;
  name: string;
  score: number;
  votes: number;
  coverage: number;
  conflict: boolean;
  vetoed: boolean;
  opinions: { user: string; sentiment: Sentiment; rating: number | null }[];
}

export interface Consensus {
  group: { id: number; name: string };
  members: number;
  common_restrictions: { diet: Diet; allergens: { id: number; name: string }[] };
  favorites: ConsensusItem[];
  conflicts: ConsensusItem[];
  avoid: ConsensusItem[];
}

export interface ShoppingListItem {
  id: number;
  shopping_list_id: number;
  product_id: number | null;
  custom_name: string | null;
  quantity: string;
  unit: string;
  unit_price: string | null;
  is_checked: boolean;
  checked_by: number | null;
  checked_at: string | null;
  notes: string | null;
  position: number;
  product?: Product | null;
}

export interface ShoppingList {
  id: number;
  user_id: number;
  group_id: number | null;
  name: string;
  status: ListStatus;
  planned_for: string | null;
  budget: string | null;
  completed_at: string | null;
  created_at: string;
  user?: { id: number; name: string };
  group?: { id: number; name: string } | null;
  items_count?: number;
  checked_count?: number;
  estimated_total?: number;
  items?: ShoppingListItem[];
  warnings?: { item_id: number; user: string; reasons: string[] }[];
}

export interface Report {
  key: string;
  title: string;
  description: string;
  columns: Record<string, string>;
  rows: Record<string, string | number | boolean | null>[];
  chart: 'none' | 'bar' | 'pie' | 'line' | 'stacked';
  scope: string;
}

export interface Recommendations {
  based_on_categories: string[];
  month: number;
  products: Product[];
}