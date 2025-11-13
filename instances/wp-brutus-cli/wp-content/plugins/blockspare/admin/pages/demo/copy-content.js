import React, { useState } from 'react'
const { __ } = wp.i18n;
import {
    Button,
    Tooltip

} from '@wordpress/components';
const Copycontent = ({ contentdata }) => {
    const [copySuccessMessage, setCopySuccessMessage] = useState('Copy Pattern');
    const copyToClipboard = (text) => {
        navigator.clipboard.writeText(text)
            .then(() => {
                setCopySuccessMessage('Copied');
                // Clear success message after a certain time if needed
                setTimeout(() => setCopySuccessMessage('Copy Pattern'), 2000);
            })
            .catch((err) => {
                setCopySuccessMessage('Unable to copy to clipboard', err);
            });
    };

    return (
        <Tooltip
            text={copySuccessMessage}
            hideOnClick={false}
            delay={0}
        >
            <Button
                isSmall
                className="bs-layout-action-button bs-layout-copy-button"
                onClick={() => copyToClipboard(contentdata)}
                showTooltip
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 682.667 682.667">
                    <path d="M565 640H225c-41.36 0-75-33.64-75-75V225c0-41.36 33.64-75 75-75h340c41.36 0 75 33.64 75 75v340c0 41.36-33.64 75-75 75zM225 200c-13.785 0-25 11.215-25 25v340c0 13.785 11.215 25 25 25h340c13.785 0 25-11.215 25-25V225c0-13.785-11.215-25-25-25zM100 440H75c-13.785 0-25-11.215-25-25V75c0-13.785 11.215-25 25-25h340c13.785 0 25 11.215 25 25v23.75h50V75c0-41.36-33.64-75-75-75H75C33.64 0 0 33.64 0 75v340c0 41.36 33.64 75 75 75h25zm0 0" data-original="#000000" />
                </svg>
            </Button>
        </Tooltip>

    )

}

export default Copycontent