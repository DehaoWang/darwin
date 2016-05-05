package com.ncfgroup.darwin.server;

import java.io.IOException;
import java.util.ArrayList;
import java.util.Enumeration;
import java.util.List;
import java.util.Map;

import javax.servlet.ServletException;
import javax.servlet.http.HttpServletRequest;
import javax.servlet.http.HttpServletResponse;

import org.apache.log4j.Logger;

import com.ncfgroup.darwin.commons.cli.CommadLineBuilder;
import com.ncfgroup.darwin.commons.cli.DefaultOption;
import com.ncfgroup.darwin.net.ServiceHandler;
import com.ncfgroup.darwin.util.IDUtils;


/**
 * 
 * <p>Title: Service</p>
 * 
 * <p>Copyright: Copyright (c) 2015</p>
 * <p>Company: ncfgroup</p>
 * 
 * @version 1.0
 * 
 * @author wangdehao 
 *
 */
public class Service extends ServiceHandler {
	
	public static String host;
	
	public static int port;
	
	private ServiceCenter service = new ServiceCenter();

	public void handle(String target, HttpServletRequest request,
			HttpServletResponse response, int dispatch) throws IOException,
			ServletException {
	
		// initial response information
		handle(request,response);
		
		// read params
		String[] args = mapToArray(request);
		
		// parse params
		CommadLineBuilder builder = new CommadLineBuilder();
		builder.addOptions(DefaultOption.getAllOption());
		if (!builder.build(args)) 
			return;
		
		Map<String, String> params = builder.getAllOptionValues();
		String bid = assign();
		logger.info("RHOST[" + request.getRemoteHost() + 
				    "], BID[" + bid + "], params-info : " + params);
		
		// process
		String result = job(bid, params);
		
//		System.out.println("%%%"+result);
		
		// response initial
		response.getOutputStream().write(result.getBytes());		
		response.setCharacterEncoding("utf-8");
		response.setContentType("text/html");
		
		// the command is not valid
		response.setHeader("Status", "200");
		logger.info("response status - 200");	
		
	}
	
	private String[] mapToArray(HttpServletRequest request) {
		@SuppressWarnings("unchecked")
		Enumeration<String> enums = request.getParameterNames();
		
		List<String> list = new ArrayList<String>();
		while(enums.hasMoreElements()) {
			String pname = enums.nextElement();
			list.add("-" + pname);
			list.add(request.getParameter(pname));
		}
		int size = list.size();		
		String[] args = new String[size];
		list.toArray(args);
		return args;
	}
	
	public String assign() {		
		return IDUtils.applyBID();
	}
	
	public String job(String bid, Map<String, String> params) {		
		String result = service.process(bid, params);
		return result;
	}
	
	private static Logger logger = Logger.getLogger(Service.class);
	
}
