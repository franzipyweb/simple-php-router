<?php
namespace Pecee\SimpleRouter\Route;

use Pecee\Http\Request;
use Pecee\SimpleRouter\Exceptions\HttpException;
use Pecee\SimpleRouter\Exceptions\NotFoundHttpException;

abstract class Route implements IRoute
{
	const REQUEST_TYPE_GET = 'get';
	const REQUEST_TYPE_POST = 'post';
	const REQUEST_TYPE_PUT = 'put';
	const REQUEST_TYPE_PATCH = 'patch';
	const REQUEST_TYPE_OPTIONS = 'options';
	const REQUEST_TYPE_DELETE = 'delete';

	public static $requestTypes = [
		self::REQUEST_TYPE_GET,
		self::REQUEST_TYPE_POST,
		self::REQUEST_TYPE_PUT,
		self::REQUEST_TYPE_PATCH,
		self::REQUEST_TYPE_OPTIONS,
		self::REQUEST_TYPE_DELETE,
	];

	protected $paramModifiers = '{}';
	protected $paramOptionalSymbol = '?';
	protected $group;
	protected $parent;
	protected $callback;
	protected $defaultNamespace;

	/* Default options */
	protected $namespace;
	protected $regex;
	protected $requestMethods = [];
	protected $where = [];
	protected $parameters = [];
	protected $middlewares = [];

	public function renderRoute(Request $request)
	{
		if ($this->getCallback() !== null && is_callable($this->getCallback())) {

			/* When the callback is a function */
			call_user_func_array($this->getCallback(), $this->getParameters());

		} else {

			/* When the callback is a method */
			$controller = explode('@', $this->getCallback());
			$className = $this->getNamespace() . '\\' . $controller[0];

			$class = $this->loadClass($className);
			$method = $controller[1];

			if (!method_exists($class, $method)) {
				throw new NotFoundHttpException(sprintf('Method %s does not exist in class %s', $method, $className), 404);
			}

			$parameters = array_filter($this->getParameters(), function ($var) {
				return ($var !== null);
			});

			call_user_func_array([$class, $method], $parameters);

			return $class;
		}

		return null;
	}

	protected function parseParameters($route, $url, $parameterRegex = '[\w]+')
	{
		$parameterNames = [];
		$regex = '';
		$lastCharacter = '';
		$isParameter = false;
		$parameter = '';

		for ($i = 0; $i < strlen($route); $i++) {

			$character = $route[$i];

			if ($character === '{') {
				/* Remove "/" and "\" from regex */
				if (substr($regex, strlen($regex) - 1) === '/') {
					$regex = substr($regex, 0, strlen($regex) - 2);
				}

				$isParameter = true;
			} elseif ($isParameter && $character === '}') {
				$required = true;

				/* Check for optional parameter and use custom parameter regex if it exists */
				if (is_array($this->where) === true && isset($this->where[$parameter])) {
					$parameterRegex = $this->where[$parameter];
				}

				if ($lastCharacter === '?') {
					$parameter = substr($parameter, 0, strlen($parameter) - 1);
					$regex .= '(?:\/?(?P<' . $parameter . '>' . $parameterRegex . ')[^\/]?)?';
					$required = false;
				} else {
					$regex .= '\/?(?P<' . $parameter . '>' . $parameterRegex . ')[^\/]?';
				}

				$parameterNames[] = [
					'name'     => $parameter,
					'required' => $required,
				];

				$parameter = '';
				$isParameter = false;
			} elseif ($isParameter) {
				$parameter .= $character;
			} elseif ($character === '/') {
				$regex .= '\\' . $character;
			} else {
				$regex .= str_replace('.', '\\.', $character);
			}

			$lastCharacter = $character;
		}

		$parameterValues = [];

		if (preg_match('/^' . $regex . '\/?$/is', $url, $parameterValues)) {

			$parameters = [];

			foreach ($parameterNames as $name) {
				$parameterValue = isset($parameterValues[$name['name']]) ? $parameterValues[$name['name']] : null;

				if ($name['required'] && $parameterValue === null) {
					throw new HttpException('Missing required parameter ' . $name['name'], 404);
				}

				if ($name['required'] === false && $parameterValue === null) {
					continue;
				}

				$parameters[$name['name']] = $parameterValue;
			}

			return $parameters;
		}

		return null;
	}

	protected function loadClass($name)
	{
		if (!class_exists($name)) {
			throw new HttpException(sprintf('Class %s does not exist', $name), 500);
		}

		return new $name();
	}

	/**
	 * Returns callback name/identifier for the current route based on the callback.
	 * Useful if you need to get a unique identifier for the loaded route, for instance
	 * when using translations etc.
	 *
	 * @return string
	 */
	public function getIdentifier()
	{
		if (strpos($this->callback, '@') !== false) {
			return $this->callback;
		}

		return 'function_' . md5($this->callback);
	}

	/**
	 * Set allowed request methods
	 *
	 * @param array $methods
	 * @return static $this
	 */
	public function setRequestMethods(array $methods)
	{
		$this->requestMethods = $methods;

		return $this;
	}

	/**
	 * Get allowed request methods
	 *
	 * @return array
	 */
	public function getRequestMethods()
	{
		return $this->requestMethods;
	}

	/**
	 * @return IRoute|null
	 */
	public function getParent()
	{
		return $this->parent;
	}

	/**
	 * Get the group for the route.
	 *
	 * @return IGroupRoute|null
	 */
	public function getGroup()
	{
		return $this->group;
	}

	/**
	 * Set group
	 *
	 * @param IGroupRoute $group
	 * @return static $this
	 */
	public function setGroup(IGroupRoute $group)
	{
		$this->group = $group;

		return $this;
	}

	/**
	 * Set parent route
	 *
	 * @param IRoute $parent
	 * @return static $this
	 */
	public function setParent(IRoute $parent)
	{
		$this->parent = $parent;

		return $this;
	}

	/**
	 * Set callback
	 *
	 * @param string $callback
	 * @return static
	 */
	public function setCallback($callback)
	{
		$this->callback = $callback;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getCallback()
	{
		return $this->callback;
	}

	public function getMethod()
	{
		if (strpos($this->callback, '@') !== false) {
			$tmp = explode('@', $this->callback);

			return $tmp[1];
		}

		return null;
	}

	public function getClass()
	{
		if (strpos($this->callback, '@') !== false) {
			$tmp = explode('@', $this->callback);

			return $tmp[0];
		}

		return null;
	}

	public function setMethod($method)
	{
		$this->callback = sprintf('%s@%s', $this->getClass(), $method);

		return $this;
	}

	public function setClass($class)
	{
		$this->callback = sprintf('%s@%s', $class, $this->getMethod());

		return $this;
	}

	/**
	 * @param string $namespace
	 * @return static $this
	 */
	public function setNamespace($namespace)
	{
		$this->namespace = $namespace;

		return $this;
	}

	/**
	 * @param string $namespace
	 * @return static $this
	 */
	public function setDefaultNamespace($namespace)
	{
		$this->defaultNamespace = $namespace;

		return $this;
	}

	public function getDefaultNamespace()
	{
		return $this->defaultNamespace;
	}

	/**
	 * @return string
	 */
	public function getNamespace()
	{
		return ($this->namespace === null) ? $this->defaultNamespace : $this->namespace;
	}

	/**
	 * Add regular expression match for the entire route.
	 *
	 * @param string $regex
	 * @return static
	 */
	public function setMatch($regex)
	{
		$this->regex = $regex;

		return $this;
	}

	/**
	 * Get regular expression match used for matching route (if defined).
	 *
	 * @return string
	 */
	public function getMatch()
	{
		return $this->regex;
	}

	/**
	 * Export route settings to array so they can be merged with another route.
	 *
	 * @return array
	 */
	public function toArray()
	{
		$values = [];

		if ($this->namespace !== null) {
			$values['namespace'] = $this->namespace;
		}

		if (count($this->requestMethods) > 0) {
			$values['method'] = $this->requestMethods;
		}

		if (count($this->where) > 0) {
			$values['where'] = $this->where;
		}

		if (count($this->parameters) > 0) {
			$values['parameters'] = $this->parameters;
		}

		if (count($this->middlewares) > 0) {
			$values['middleware'] = $this->middlewares;
		}

		return $values;
	}

	/**
	 * Merge with information from another route.
	 *
	 * @param array $values
	 * @param bool $merge
	 * @return static $this
	 */
	public function setSettings(array $values, $merge = false)
	{
		if (isset($values['namespace']) && $this->namespace === null) {
			$this->setNamespace($values['namespace']);
		}

		if (isset($values['method'])) {
			$this->setRequestMethods(array_merge($this->requestMethods, (array)$values['method']));
		}

		if (isset($values['where'])) {
			$this->setWhere(array_merge($this->where, (array)$values['where']));
		}

		if (isset($values['parameters'])) {
			$this->setParameters(array_merge($this->parameters, (array)$values['parameters']));
		}

		// Push middleware if multiple
		if (isset($values['middleware'])) {
			$this->setMiddlewares(array_merge((array)$values['middleware'], $this->middlewares));
		}

		return $this;
	}

	/**
	 * Get parameter names.
	 *
	 * @return array
	 */
	public function getWhere()
	{
		return $this->where;
	}

	/**
	 * Set parameter names.
	 *
	 * @param array $options
	 * @return static
	 */
	public function setWhere(array $options)
	{
		$this->where = $options;

		return $this;
	}

	/**
	 * Add regular expression parameter match.
	 * Alias for LoadableRoute::where()
	 *
	 * @see LoadableRoute::where()
	 * @param array $options
	 * @return static
	 */
	public function where(array $options)
	{
		return $this->where($options);
	}

	/**
	 * Get parameters
	 *
	 * @return array
	 */
	public function getParameters()
	{
		return $this->parameters;
	}

	/**
	 * Get parameters
	 *
	 * @param array $parameters
	 * @return static $this
	 */
	public function setParameters(array $parameters)
	{
		$this->parameters = $parameters;

		return $this;
	}

	/**
	 * Set middleware class-name
	 *
	 * @param string $middleware
	 * @return static
	 */
	public function setMiddleware($middleware)
	{
		$this->middlewares[] = $middleware;

		return $this;
	}

	/**
	 * Set middlewares array
	 *
	 * @param array $middlewares
	 * @return $this
	 */
	public function setMiddlewares(array $middlewares)
	{
		$this->middlewares = $middlewares;

		return $this;
	}

	/**
	 * @return string|array
	 */
	public function getMiddlewares()
	{
		return $this->middlewares;
	}

}