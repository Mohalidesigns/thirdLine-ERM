import AuthenticatedLayout from '@thirdline/ui/Layouts/AuthenticatedLayout';
import PeriodSelector from '@/Components/PeriodSelector';
import SearchBox from '@/Components/SearchBox';

/**
 * This product's shell: the shared layout, with the two slots it leaves open
 * filled by the components that know this product's routes.
 *
 * The shared layout cannot import SearchBox or PeriodSelector directly —
 * they name `search.suggest` and `risk.periods.select`, and Ziggy throws on a
 * route name the other product has never heard of. It keeps the placement and
 * the visibility rules; this supplies what goes in them.
 *
 * It is named AppLayout rather than sitting at the shell's old path: leaving a
 * new file where the moved one used to be makes git see modify-plus-add instead
 * of a rename, and `git log --follow` on the packaged shell stops at the move
 * instead of reaching its history. The phase requires that history to survive,
 * and 157 rewritten import lines are mechanical and checked by the build.
 */
export default function AppLayout(props) {
    return (
        <AuthenticatedLayout
            {...props}
            search={<SearchBox />}
            periodSelector={<PeriodSelector />}
        />
    );
}
