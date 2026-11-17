import { Route, Routes } from 'react-router-dom';
import { GuestOnly, RequireAdmin, RequireAuth } from '@/auth/guards';
import Layout from '@/components/Layout';
import LoginPage from '@/pages/auth/LoginPage';
import RegisterPage from '@/pages/auth/RegisterPage';
import DashboardPage from '@/pages/DashboardPage';
import ProductsPage from '@/pages/products/ProductsPage';
import ProductDetailPage from '@/pages/products/ProductDetailPage';
import ProductFormPage from '@/pages/products/ProductFormPage';
import OffSearchPage from '@/pages/products/OffSearchPage';
import PreferencesPage from '@/pages/PreferencesPage';
import GroupsPage from '@/pages/groups/GroupsPage';
import GroupDetailPage from '@/pages/groups/GroupDetailPage';
import ShoppingListsPage from '@/pages/shopping/ShoppingListsPage';
import ShoppingListDetailPage from '@/pages/shopping/ShoppingListDetailPage';
import RestaurantsPage from '@/pages/RestaurantsPage';
import StatisticsPage from '@/pages/StatisticsPage';
import ProfilePage from '@/pages/ProfilePage';
import AdminUsersPage from '@/pages/admin/AdminUsersPage';
import AdminTaxonomyPage from '@/pages/admin/AdminTaxonomyPage';
import NotFoundPage from '@/pages/NotFoundPage';

export default function App() {
  return (
    <Routes>
      <Route element={<GuestOnly />}>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
      </Route>

      <Route element={<RequireAuth />}>
        <Route element={<Layout />}>
          <Route index element={<DashboardPage />} />
          <Route path="products" element={<ProductsPage />} />
          <Route path="products/new" element={<ProductFormPage />} />
          <Route path="products/import" element={<OffSearchPage />} />
          <Route path="products/:id" element={<ProductDetailPage />} />
          <Route path="products/:id/edit" element={<ProductFormPage />} />
          <Route path="preferences" element={<PreferencesPage />} />
          <Route path="groups" element={<GroupsPage />} />
          <Route path="groups/:id" element={<GroupDetailPage />} />
          <Route path="shopping-lists" element={<ShoppingListsPage />} />
          <Route path="shopping-lists/:id" element={<ShoppingListDetailPage />} />
          <Route path="restaurants" element={<RestaurantsPage />} />
          <Route path="stats" element={<StatisticsPage />} />
          <Route path="profile" element={<ProfilePage />} />

          <Route path="admin" element={<RequireAdmin />}>
            <Route path="users" element={<AdminUsersPage />} />
            <Route path="taxonomy" element={<AdminTaxonomyPage />} />
          </Route>
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Route>
    </Routes>
  );
}
