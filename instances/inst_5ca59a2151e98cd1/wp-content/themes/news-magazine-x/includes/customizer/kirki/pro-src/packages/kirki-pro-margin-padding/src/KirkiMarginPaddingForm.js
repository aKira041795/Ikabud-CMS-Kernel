import { useRef, useState } from "react";

const KirkiMarginPaddingForm = (props) => {
  const { control, customizerSetting, defaultArray, valueArray, valueUnit } =
    props;

  const [inputValues, setInputValues] = useState(() => {
    return valueArray;
  });

  const getSingleValueAsObject = (value) => {
    let unit = "";
    let number = "";
    let negative = "";

    if ( typeof value == "boolean" ) {
      return {
        unit: '',
        number: value,
      };
    }

    if ("" !== value) {
      value = "string" !== typeof value ? value.toString() : value;
      value = value.trim();
      negative = -1 < value.indexOf("-") ? "-" : "";
      value = value.replace(negative, "");

      if ("" !== value) {
        unit = value.replace(/\d+/g, "");
        number = value.replace(unit, "");
        number = negative + number.trim();
        number = parseFloat(number);
      } else {
        number = negative;
      }
    }

    return {
      unit: unit,
      number: number,
    };
  };

  const getValuesForInput = (values) => {
    let singleValue;

    for (const position in values) {
      if (Object.hasOwnProperty.call(values, position)) {
        singleValue = getSingleValueAsObject(values[position]);
        values[position] = singleValue.number;
      }
    }

    return values;
  };

  const getValuesForCustomizer = (values) => {
    let singleValue;

    for (const position in values) {
      if (Object.hasOwnProperty.call(values, position)) {
        singleValue = values[position];

        if ("" !== singleValue) {
          singleValue = getSingleValueAsObject(singleValue);
          singleValue = singleValue.number;
          singleValue = typeof singleValue == "boolean" ? singleValue : singleValue + valueUnit;
        }

        values[position] = singleValue;
      }
    }

    return values;
  };

  control.updateComponentState = (val) => {
    setInputValues(getValuesForInput(val));
  };

  const handleChange = (e, position) => {
    let values = { ...inputValues };

    if ( values.isLinked ) {
      values['top'] = e.target.value;
      values['right'] = e.target.value;
      values['bottom'] = e.target.value;
      values['left'] = e.target.value;
    } else {
      values[position] = e.target.value;
    }

    customizerSetting.set(getValuesForCustomizer(values));
  };

  const handleReset = (e) => {
    const values =
      "" !== props.default && "undefined" !== typeof props.default
        ? defaultArray
        : valueArray;

    customizerSetting.set(getValuesForCustomizer(values));
  };

  const handleLinkClick = (e) => {
    let values = { ...inputValues, isLinked: !inputValues.isLinked };
    
    customizerSetting.set(getValuesForCustomizer(values));
  };

  const handleKeyDown = (e, position) => {
    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      e.preventDefault();
      
      let currentValue = parseFloat(e.target.value) || 0;
      const step = 1; // We can make this configurable if needed
      
      if (e.key === 'ArrowUp') {
        currentValue += step;
      } else if (e.key === 'ArrowDown') {
        currentValue -= step;
      }

      // Update the value
      let values = { ...inputValues };
      
      if (values.isLinked) {
        values['top'] = currentValue;
        values['right'] = currentValue;
        values['bottom'] = currentValue;
        values['left'] = currentValue;
      } else {
        values[position] = currentValue;
      }

      // Update both the state and customizer
      setInputValues(values);
      customizerSetting.set(getValuesForCustomizer(values));
    }
  };

  // Preparing for the template.
  const fieldId = `kirki-control-input-${props.type}-top`;
  const unitRef = useRef(null);

  const makeMapable = () => {
    const items = [];

    for (const position in inputValues) {
      if (Object.hasOwnProperty.call(inputValues, position) ) {
        if ( typeof inputValues[position] != "boolean" ) {
          items.push({ position: position, value: inputValues[position] });
        }
      }
    }

    return items;
  };
  

  return (
    <div className="kirki-control-form dashicons-picker" tabIndex="1">
      {(props.label || props.description) && (
        <>
          <label className="kirki-control-label" htmlFor={fieldId}>
            {props.label && (
              <span className="customize-control-title">{props.label}</span>
            )}

            {props.description && (
              <span
                className="customize-control-description description"
                dangerouslySetInnerHTML={{ __html: props.description }}
              />
            )}
          </label>

          <div
            className="customize-control-notifications-container"
            ref={props.setNotificationContainer}
          />
        </>
      )}

      <button
        type="button"
        className="kirki-control-reset"
        onClick={handleReset}
      >
        <i className="dashicons dashicons-image-rotate"></i>
      </button>

      <div className="kirki-control-cols">
        <div className="kirki-control-left-col">
          <div class="kirki-control-fields">
            {makeMapable(inputValues).map((item) => {
              const className = `kirki-control-input kirki-control-input-${item.position}`;
              const id = `kirki-control-input-${props.type}-${item.position}`;

              return (
                <div class="kirki-control-field">
                  <input
                    id={id}
                    type="text"
                    value={item.value || 0 === item.value ? item.value : ""}
                    className={className}
                    onChange={(e) => handleChange(e, item.position)}
                    onKeyDown={(e) => handleKeyDown(e, item.position)}
                  />
                  <label class="kirki-control-sublabel" htmlFor={id}>
                    {item.position}
                  </label>
                </div>
              );
            })}
          </div>
        </div>
        <div className="kirki-control-right-col">
          <span className={`newsx-spacing-linked ${inputValues.isLinked ? 'active' : ''}`} onClick={handleLinkClick}>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 5C10.3431 5 9 6.34315 9 8V10H7V8C7 5.23858 9.23858 3 12 3C14.7614 3 17 5.23858 17 8V10H15V8C15 6.34315 13.6569 5 12 5Z" fill="#0284c7"></path><path fill-rule="evenodd" clip-rule="evenodd" d="M12 19C10.3431 19 9 17.6569 9 16V14H7V16C7 18.7614 9.23858 21 12 21C14.7614 21 17 18.7614 17 16V14H15V16C15 17.6569 13.6569 19 12 19Z" fill="#0284c7"></path><path fill-rule="evenodd" clip-rule="evenodd" d="M11 16V8H13V16H11Z" fill="#0284c7"></path></svg>
          </span>
        </div>
      </div>
    </div>
  );
};

export default KirkiMarginPaddingForm;
