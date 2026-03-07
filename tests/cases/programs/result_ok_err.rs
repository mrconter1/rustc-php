// stdout: 10
// stdout: 99
// stdout: ok
// stdout: err
// exit: 0

fn unwrap_ok(r: Result<i32, i32>) -> i32 {
    match r {
        Result::<i32, i32>::Ok(x) => x,
        Result::<i32, i32>::Err(_) => 0,
    }
}

fn is_ok(r: Result<i32, i32>) -> bool {
    match r {
        Result::<i32, i32>::Ok(_) => true,
        Result::<i32, i32>::Err(_) => false,
    }
}

fn main() {
    let r1: Result<i32, i32> = Result::<i32, i32>::Ok(10);
    let r2: Result<i32, i32> = Result::<i32, i32>::Err(99);
    println!("{}", unwrap_ok(r1));
    match r2 {
        Result::<i32, i32>::Ok(_) => { println!("ok"); }
        Result::<i32, i32>::Err(e) => { println!("{}", e); }
    }
    if is_ok(r1) {
        println!("ok");
    } else {
        println!("err");
    }
    if is_ok(r2) {
        println!("ok");
    } else {
        println!("err");
    }
    exit(0);
}
