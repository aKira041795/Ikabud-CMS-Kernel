import React from 'react'

const SettingWithInput = ({ title, description, value, handleChange, name, handleBlur, success }) => {
    return (
        <div className="blockspare__settings">
            <div className="blockspare__settings-info">
                <h3 className="blockspare__settings-title">{title}</h3>
                <div className="blockspare__input-wrap">
                    <input className="blockspare-admin__input-field" type="text" name={name} value={value} onChange={handleChange} onBlur={handleBlur} />
                    <span className="blockspare-admin__input-field--value-type">
                        px
                    </span>
                    {success === true &&
                        <p>success</p>
                    }
                </div>
            </div>
            <p className="blockspare__settings-description">{description}</p>
        </div>
    )
}

export default SettingWithInput
