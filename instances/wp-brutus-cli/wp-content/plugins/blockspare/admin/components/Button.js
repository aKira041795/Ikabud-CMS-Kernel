import React from 'react'

const Button = ({children, handleClick}) => {
    return (
        <button className="blocksape-dashboard__button" onClick={handleClick}>{children}</button>
    )
}

export default Button
