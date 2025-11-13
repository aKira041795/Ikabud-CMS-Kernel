import icons from '../../../src/assets/icons/index'

const { __ } = wp.i18n;

const PremiumText = ({ link }) => {


    return (
        <div className="bs-layout-image-overlay">
            <div className="bs-layout-premium">
                <h4>{__('Premium Designs Await!', 'blockspare')}</h4>
                <p>{__('Unlock our entire design library. Elevate your site instantly.', 'blockspare')}</p>
                <div className='bs-premium-button-group'>

                    <a href='https://www.blockspare.com/' target="_blank" className="bs-layout-button bs-premium-button">
                        {icons.diamond}

                        {__('Learn More', 'blockspare')}
                    </a>
                    <a href={link} target="_blank" className="bs-layout-button bs-premium-button">
                        {icons.view}
                        {__('View demo', 'blockspare')}
                    </a>
                </div>
            </div>
        </div>
    )
}

export default PremiumText