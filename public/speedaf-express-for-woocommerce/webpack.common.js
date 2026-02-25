const path =  require( 'path');
const {CleanWebpackPlugin} = require( 'clean-webpack-plugin');



module.exports ={
    externals: {
        'jquery': 'window.jQuery'
    },
    entry : {
        speedaf_admin: './assets/src/index.js',
        speedaf_tracking: './assets/src/speedaf-tracking.min.js'
    },
    output:{
        filename: '[name].bundle.js',
        path: path.resolve(__dirname, './assets/dist'),
        publicPath: './assets/'
    },
     plugins:[
        new CleanWebpackPlugin()
     ]
    
}
