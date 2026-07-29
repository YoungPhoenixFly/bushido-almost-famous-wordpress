const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry(),
		admin: './src/admin/admin.js',
		'public-portal': './src/public-portal/index.js',
		// Block editor scripts. Compiled into assets/js/blocks/<block>/index.js
		// (with the .asset.php DependencyExtractionWebpackPlugin emits per
		// entry), which is where each block.json's `editorScript` file: path
		// points.
		'blocks/campaign-widget/index': './blocks/campaign-widget/index.js',
		'blocks/portal/index': './blocks/portal/index.js',
	},
	output: {
		...defaultConfig.output,
		path: __dirname + '/assets/js',
		filename: '[name].js',
	},
};
