/**
 * @package
 * @license GPL-2.0-or-later
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

/**
 * Static editor preview — the real console is rendered server-side on the
 * published page (render.php delegates to the [almost-famous-portal]
 * shortcode), so the editor only needs a placeholder.
 */
function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<Placeholder
				icon="megaphone"
				label={ __(
					'Bushido Almost Famous Portal',
					'bushido-almost-famous'
				) }
				instructions={ __(
					'The campaign console renders here on the published page. Signed-in visitors with campaign access see their dashboard; everyone else sees the sign-in gate.',
					'bushido-almost-famous'
				) }
			/>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
