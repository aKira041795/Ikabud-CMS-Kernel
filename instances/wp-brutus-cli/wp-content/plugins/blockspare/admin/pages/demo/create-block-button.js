import {
    Button,
    Tooltip,
    Dashicon
} from '@wordpress/components';

const { __ } = wp.i18n;

const CreateBlockButton = ({ href }) => {

    return (
        <Tooltip 
            text={__('Use Pattern', 'blockspare')}
        >
            <Button 
                isSmall
                className="bs-layout-action-button bs-layout-create-block-button"
                href={href}
                target='_blank'
            >
                <Dashicon
					icon={'plus-alt2'}
                    className='bs-layout-action-button-icon'
				/>
            </Button>
        </Tooltip>
    )
}

export default CreateBlockButton
