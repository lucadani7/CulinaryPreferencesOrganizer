import { useQuery } from '@tanstack/react-query';
import { api } from './client';
import type { Allergen, Category, ExchangeRates, Group } from './types';

/** Reference data used by many forms. */
export const useCategories = () =>
  useQuery({ queryKey: ['categories'], queryFn: () => api.get<Category[]>('/categories'), staleTime: 5 * 60_000 });

export const useAllergens = () =>
  useQuery({ queryKey: ['allergens'], queryFn: () => api.get<Allergen[]>('/allergens'), staleTime: 5 * 60_000 });

export const useMyGroups = () => useQuery({ queryKey: ['groups'], queryFn: () => api.get<Group[]>('/groups') });

/** Latest ECB rates and the user's local currency; the API refreshes them once a day. */
export const useExchangeRates = () =>
  useQuery({ queryKey: ['exchange-rates'], queryFn: () => api.get<ExchangeRates>('/exchange-rates'), staleTime: 60 * 60_000 });
