import { useState } from 'react';
import { Link } from 'react-router-dom';
import type { Product } from '@/api/types';
import Money from './Money';
import { GradeBadge, Tag } from './ui';
import SentimentPicker from './SentimentPicker';

export default function ProductCard({ product, showPicker = true }: { product: Product; showPicker?: boolean }) {
  // images from Open Food Facts can disappear; fall back to the placeholder instead of a broken icon
  const [imageFailed, setImageFailed] = useState(false);
  const allergenNames = (product.allergens ?? []).map((a) => a.name).join(', ');

  return (
    <article className={`product-card ${product.has_my_allergens ? 'product-card--warn' : ''}`}>
      <Link to={`/products/${product.id}`} className="product-card__link">
        <div className="product-card__image">
          {product.image_url && !imageFailed ? (
            <img src={product.image_url} alt="" loading="lazy" onError={() => setImageFailed(true)} />
          ) : (
            <span aria-hidden>🍽️</span>
          )}
          <div className="product-card__grade">
            <GradeBadge grade={product.nutriscore} />
          </div>
        </div>
        <div className="product-card__body">
          <h3>{product.name}</h3>
          <p className="muted">
            {/* a non-breaking space keeps the line height when there is no brand or quantity */}
            {[product.brand, product.quantity].filter(Boolean).join(' · ') || '\u00a0'}
          </p>
          <div className="tags">
            {product.category && <Tag>{product.category.name}</Tag>}
            {product.is_vegan && <Tag tone="green">vegan</Tag>}
            {!product.is_vegan && product.is_vegetarian && <Tag tone="green">vegetarian</Tag>}
            {product.has_my_allergens && (
              <span title={allergenNames ? `Contains: ${allergenNames}` : undefined}>
                <Tag tone="red">⚠ your allergens</Tag>
              </span>
            )}
            {product.source === 'off' && <Tag tone="blue">OFF</Tag>}
          </div>
          <p className="product-card__price"><Money value={product.price} /></p>
        </div>
      </Link>
      {/* outside the link: a click on a sentiment must not open the product page */}
      {showPicker && (
        <div className="product-card__footer">
          <SentimentPicker compact targetType="product" targetId={product.id} value={product.my_sentiment} />
        </div>
      )}
    </article>
  );
}