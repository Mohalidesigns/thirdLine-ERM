export default function RatingBadge({ rating }) {
    const config = {
        critical: { class: 'badge-critical', dot: 'bg-red-500' },
        high: { class: 'badge-high', dot: 'bg-orange-500' },
        medium: { class: 'badge-medium', dot: 'bg-yellow-500' },
        moderate: { class: 'badge-medium', dot: 'bg-yellow-500' },
        low: { class: 'badge-low', dot: 'bg-green-500' },
    };
    const c = config[rating] || config.medium;
    return (
        <span className={`badge ${c.class}`}>
            <span className={`w-1.5 h-1.5 rounded-full ${c.dot} mr-1.5`}></span>
            {rating ? rating.charAt(0).toUpperCase() + rating.slice(1) : 'N/A'}
        </span>
    );
}
